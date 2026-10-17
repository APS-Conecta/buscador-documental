<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use DateTimeImmutable;
use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Acl\ExclusionUnavailable;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\Share\IShare;
use Throwable;

/**
 * The live permission recheck: the authority over the file_acl prefilter (ADR-0003). One instance per request.
 * A hit survives only if the user has at least one readable node for it (any mount counts), the share that gives
 * that node has not expired, and neither the file nor an ancestor carries `rag-excluded`. A node whose share forbids
 * download still survives, but with snippetAllowed = false (title only, ADR-0009).
 *
 * Reads access through the node API (getById) and share objects only, never from the filecache columns.
 * Failure policy: one hit failing drops that hit; the exclusion lookup failing fails the whole recheck closed.
 * Nothing is remembered between calls except the user's folder handle, and nothing from a dropped hit is logged.
 */
class Recheck {
	private int $dropped = 0;
	/** @var array<string, \OCP\Files\Folder> */
	private array $homes = [];

	public function __construct(private IRootFolder $root, private ExclusionChecker $exclusion) {
	}

	/** Hits dropped by this instance so far (counter only). */
	public function dropped(): int {
		return $this->dropped;
	}

	/**
	 * @param list<int> $fileIds
	 * @return array<int, RecheckResult> surviving hits by file id, in the order given
	 * @throws ExclusionUnavailable when exclusion cannot be established for the batch; callers must show no results
	 */
	public function filter(string $uid, array $fileIds): array {
		$readable = [];
		$errors = 0;
		$ids = array_unique($fileIds);
		foreach ($ids as $fileId) {
			try {
				$best = $this->bestNode($uid, $fileId);
			} catch (Throwable) {
				$best = null;
				$errors++;
			}
			if ($best === null) {
				$this->dropped++;
				continue;
			}
			$readable[$fileId] = $best;
		}

		if ($ids !== [] && $errors === count($ids)) {
			throw new ExclusionUnavailable('The file layer failed for every hit'); // systemic, not per-file: do not report "no results"
		}

		$excluded = $this->exclusion->excludedAmong(array_keys($readable), true);

		$out = [];
		foreach ($fileIds as $fileId) {
			if (!isset($readable[$fileId]) || isset($out[$fileId])) {
				continue;
			}
			if (($excluded[$fileId] ?? true) === true) {
				$this->dropped++;
				continue;
			}
			[$node, $snippetAllowed] = $readable[$fileId];
			$out[$fileId] = new RecheckResult($fileId, $node, $snippetAllowed);
		}
		return $out;
	}

	/** @return array{0: Node, 1: bool}|null the best readable node and whether it allows download */
	private function bestNode(string $uid, int $fileId): ?array {
		$best = null;
		foreach ($this->nodesFor($uid, $fileId) as $node) {
			$verdict = $this->judge($node);
			if ($verdict === null) {
				continue;
			}
			if ($verdict) {
				return [$node, true];
			}
			$best ??= [$node, false];
		}
		return $best;
	}

	/** @return bool|null null: unusable; true: readable with download; false: readable, download disabled */
	private function judge(Node $node): ?bool {
		if (!$node->isReadable()) {
			return null;
		}
		$share = $this->shareOf($node);
		if ($share === null) {
			return true;
		}
		$expires = $share->getExpirationDate();
		if ($expires !== null && $expires <= new DateTimeImmutable()) {
			return null;
		}
		return $share->getAttributes()?->getAttribute('permissions', 'download') !== false;
	}

	/** @return list<Node> every node the user reaches for this file id, over all their mounts */
	protected function nodesFor(string $uid, int $fileId): array {
		$this->homes[$uid] ??= $this->root->getUserFolder($uid); // one FS setup per request
		return array_values($this->homes[$uid]->getById($fileId));
	}

	/** The share that gives this node to the user, null when the node is not behind a share. */
	protected function shareOf(Node $node): ?IShare {
		$storage = $node->getStorage();
		return $storage->instanceOfStorage(ISharedStorage::class) ? $storage->getShare() : null;
	}
}
