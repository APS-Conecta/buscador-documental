<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCA\BuscadorDocumental\Acl\PrincipalResolver;
use OCA\BuscadorDocumental\Db\IndexDb;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The search API (S4 design section 3): modes, filters, sorts and paging over one ranked candidate list.
 *
 * Page 1 builds the list (at most 1 000 files, best first, then sorted), stores its ids and pages through it by position;
 * every page is rechecked against the live permissions and refilled from later positions when hits are dropped. The list
 * holds ids only. The semantic modes fall back to keyword search with a notice and never turn a model outage into an empty list.
 *
 * Result: status ok|too_many|unavailable|invalid_cursor|cursor_expired, message, notice (null or code + Spanish message),
 * hits (same shape as Retrieve), page (from, to, total, capped, size), cursors (first, prev, next, last; null when none).
 */
class Searcher {
	public const CAP = 1000;
	public const TIMEOUT_MS = 5000;
	private const REFILL_ROUNDS = 5;

	public function __construct(
		private IndexDb $db,
		private PrincipalResolver $principals,
		private CandidateQuery $candidates,
		private Recheck $recheck,
		private HitBuilder $hits,
		private Glossary $glossary,
		private EmbedClient $embed,
		private VectorState $vectors,
		private SnapshotStore $snapshots,
		private Cursor $cursor,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<array{mode: string, enabled: bool, reason: ?string}> */
	public function modes(): array {
		$a = $this->vectors->availability();
		return [
			['mode' => 'exacta', 'enabled' => true, 'reason' => null],
			['mode' => 'significado', 'enabled' => $a['semantic'], 'reason' => $a['reason']],
			['mode' => 'mixta', 'enabled' => $a['semantic'], 'reason' => $a['reason']],
		];
	}

	/** @return array{semantic: bool, reason: ?string, coverage: float} what the page tells people about the meaning search (aggregate only) */
	public function readiness(): array {
		$a = $this->vectors->availability();
		return ['semantic' => $a['semantic'], 'reason' => $a['reason'], 'coverage' => $a['coverage']];
	}

	/** @return array<string, mixed> */
	public function search(string $uid, SearchRequest $request): array {
		if ($request->isBlank()) {
			return $this->empty('ok', '');
		}
		try {
			$notice = null;
			if ($request->cursor !== null) {
				$token = $this->cursor->read($request->cursor, $uid);
				if ($token === null) {
					return $this->empty('invalid_cursor', 'El cursor de paginación no es válido.');
				}
				$snap = $this->snapshots->load($token['qid'], $uid);
				if ($snap === null) {
					return $this->empty('cursor_expired', 'La búsqueda caducó. Vuelva a buscar.');
				}
				$qid = $token['qid'];
				$start = $token['pos'];
				$back = $token['back'];
			} else {
				// A folder id is only a filter for folders the person can read: otherwise the filter would tell them which folders
				// sit above a file shared with them.
				if ($request->filters->folderId !== null && $this->recheck->filter($uid, [$request->filters->folderId]) === []) {
					return $this->empty('ok', '');
				}
				[$files, $chunks, $notice] = $this->db->withTimeout(self::TIMEOUT_MS, fn (): array => $this->rank($uid, $request));
				[$files, $chunks] = $this->withoutUnreadableUnmodelled($uid, $files, $chunks);
				$snap = ['file_ids' => $files, 'chunk_ids' => $chunks];
				$qid = $this->snapshots->save($uid, $files, $chunks);
				$start = 0;
				$back = null;
			}
			return $this->page($uid, $request, $snap, $qid, $start, $back, $notice);
		} catch (ExclusionUnavailable $e) {
			$this->logger->warning('buscador_documental: exclusion state unavailable, search refused (' . get_class($e) . ')');
			return $this->empty('unavailable', 'La búsqueda no está disponible en este momento.');
		} catch (Throwable $e) {
			if (str_contains($e->getMessage(), 'statement timeout')) {
				return $this->empty('too_many', 'Demasiados resultados: acote la búsqueda con más términos.');
			}
			$this->logger->error('buscador_documental: search failed (' . get_class($e) . ')');
			return $this->empty('unavailable', 'La búsqueda no está disponible en este momento.');
		}
	}

	/** @return array{0: list<int>, 1: list<int>, 2: ?array{code: string, message: string}} */
	private function rank(string $uid, SearchRequest $r): array {
		$set = IndexDb::textArray($this->principals->forUser($uid));
		$filters = $r->filters->forUser($uid, $this->vectors->uncertainBelow());
		$mode = $r->mode;
		$notice = null;
		$vector = null;
		$profile = null;
		if ($mode !== 'exacta') {
			$a = $this->vectors->availability();
			$profile = $a['semantic'] ? $this->vectors->activeProfile() : null;
			if ($profile === null) {
				[$mode, $notice] = ['exacta', ['code' => 'semantic_unavailable', 'message' => 'La búsqueda por significado aún no está disponible; se muestran resultados por palabras.']];
			} else {
				$vector = $this->embed->embedQuery($r->query, $profile, $this->vectors->embedTimeoutMs());
				if ($vector === null) {
					[$mode, $notice] = ['exacta', ['code' => 'semantic_failed', 'message' => 'No se pudo calcular el significado de la consulta ahora; se muestran resultados por palabras.']];
				}
			}
		}
		// Mixta keeps the keyword leg strict: the OR relaxation of plain keyword search only adds common-word noise that dilutes the
		// fusion, while the vector leg already covers the fuzzy matches (S4 evaluation: recall@10 0.67 with it, 0.79 without).
		$keyword = $mode === 'significado' ? [] : $this->candidates->exacta($r->query, $this->glossary->alternatives($r->query), $set, $filters, self::CAP, $mode === 'mixta' ? false : null);
		$semantic = [];
		if ($mode !== 'exacta') {
			$exact = !$this->candidates->hasAnnIndex($profile['gen'])
				|| $this->candidates->accessibleFiles($set, $filters, $this->vectors->exactScanFiles()) < $this->vectors->exactScanFiles();
			$semantic = $this->candidates->significado($vector, $profile['gen'], $set, $filters, self::CAP, $exact);
		}
		$ranked = match ($mode) {
			'exacta' => $keyword,
			'significado' => $semantic,
			default => Rrf::fuse([$keyword, $semantic], self::CAP),
		};
		$ranked = array_slice($ranked, 0, self::CAP);
		if ($r->sort !== 'relevance') {
			$ranked = $this->sorted($ranked, $r->sort);
		}
		return [array_map(static fn (array $x): int => (int)$x['file_id'], $ranked), array_map(static fn (array $x): int => (int)$x['chunk_id'], $ranked), $notice];
	}

	/**
	 * The stored principals judge most files well, but not the ones with access they cannot model. Those are rechecked now,
	 * before the list is stored, so the totals, positions and cursors the caller sees never count a file they cannot read.
	 *
	 * @param list<int> $files @param list<int> $chunks
	 * @return array{0: list<int>, 1: list<int>}
	 */
	private function withoutUnreadableUnmodelled(string $uid, array $files, array $chunks): array {
		$unmodelled = array_flip($this->candidates->unmodelledAmong($files));
		if ($unmodelled === []) {
			return [$files, $chunks];
		}
		$readable = $this->recheck->filter($uid, array_keys($unmodelled));
		$f = [];
		$c = [];
		foreach ($files as $i => $id) {
			if (!isset($unmodelled[$id]) || isset($readable[$id])) {
				$f[] = $id;
				$c[] = $chunks[$i];
			}
		}
		return [$f, $c];
	}

	/** @param list<array<string, mixed>> $ranked @return list<array<string, mixed>> */
	private function sorted(array $ranked, string $sort): array {
		$ids = array_map(static fn (array $x): int => (int)$x['file_id'], $ranked);
		$meta = [];
		foreach ($this->db->rows('SELECT file_id, name, mtime FROM aps_search.file_meta WHERE file_id = ANY(?::bigint[])', [IndexDb::textArray(array_map('strval', $ids))]) as $m) {
			$meta[(int)$m['file_id']] = ['mtime' => (int)$m['mtime'], 'name' => Glossary::fold((string)$m['name'])];
		}
		usort($ranked, static function (array $a, array $b) use ($meta, $sort): int {
			$fa = (int)$a['file_id'];
			$fb = (int)$b['file_id'];
			return match ($sort) {
				'modified_desc' => [($meta[$fb]['mtime'] ?? 0), $fa] <=> [($meta[$fa]['mtime'] ?? 0), $fb],
				'modified_asc' => [($meta[$fa]['mtime'] ?? 0), $fa] <=> [($meta[$fb]['mtime'] ?? 0), $fb],
				'name_asc' => [($meta[$fa]['name'] ?? ''), $fa] <=> [($meta[$fb]['name'] ?? ''), $fb],
				default => [($meta[$fb]['name'] ?? ''), $fa] <=> [($meta[$fa]['name'] ?? ''), $fb],
			};
		});
		return $ranked;
	}

	/**
	 * @param array{file_ids: list<int>, chunk_ids: list<int>} $snap
	 * @param ?array{code: string, message: string} $notice
	 * @return array<string, mixed>
	 */
	private function page(string $uid, SearchRequest $r, array $snap, string $qid, int $start, ?int $back, ?array $notice): array {
		$total = count($snap['file_ids']);
		$size = $r->pageSize;
		$pos = min($start, $total);
		$picked = [];
		for ($round = 0; $round < self::REFILL_ROUNDS && count($picked) < $size && $pos < $total; $round++) {
			$batch = range($pos, min($total, $pos + $size) - 1);
			$ok = $this->recheck->filter($uid, array_map(static fn (int $i): int => $snap['file_ids'][$i], $batch));
			foreach ($batch as $i) {
				$pos = $i + 1;
				if (isset($ok[$snap['file_ids'][$i]])) {
					$picked[] = [$i, $ok[$snap['file_ids'][$i]]];
					if (count($picked) === $size) {
						break;
					}
				}
			}
		}
		$etags = [];
		if ($picked !== []) {
			$ids = array_map(static fn (array $p): int => $p[1]->fileId, $picked);
			foreach ($this->db->rows("SELECT file_id, etag FROM aps_search.file_state WHERE file_id = ANY(?::bigint[]) AND text_state = 'indexed'", [IndexDb::textArray(array_map('strval', $ids))]) as $row) {
				$etags[(int)$row['file_id']] = (string)$row['etag'];
			}
		}
		$survivors = array_map(static fn (array $p): array => [['chunk_id' => $snap['chunk_ids'][$p[0]], 'etag' => $etags[$p[1]->fileId] ?? ''], $p[1]], $picked);
		$hits = $survivors === [] ? [] : $this->hits->build($r->query, $this->glossary->alternatives($r->query), $survivors, true, $this->vectors->uncertainBelow());
		$lastStart = $total > 0 ? intdiv($total - 1, $size) * $size : 0;
		return [
			'status' => 'ok',
			'message' => '',
			'notice' => $notice,
			'hits' => $hits,
			'page' => ['from' => $hits === [] ? 0 : $start + 1, 'to' => $pos, 'total' => $total, 'capped' => $total >= self::CAP, 'size' => $size],
			'cursors' => [
				'first' => $start > 0 ? $this->cursor->make($qid, 0, $uid) : null,
				'prev' => $start > 0 ? $this->cursor->make($qid, $back ?? max(0, $start - $size), $uid) : null,
				'next' => $pos < $total ? $this->cursor->make($qid, $pos, $uid, $start) : null,
				'last' => $lastStart > $start && $lastStart >= $pos ? $this->cursor->make($qid, $lastStart, $uid) : null,
			],
		];
	}

	/** @return array<string, mixed> */
	private function empty(string $status, string $message): array {
		return [
			'status' => $status, 'message' => $message, 'notice' => null, 'hits' => [],
			'page' => ['from' => 0, 'to' => 0, 'total' => 0, 'capped' => false, 'size' => 0],
			'cursors' => ['first' => null, 'prev' => null, 'next' => null, 'last' => null],
		];
	}
}
