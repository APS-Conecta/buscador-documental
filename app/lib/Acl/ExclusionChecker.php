<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Acl;

use OCP\IDBConnection;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Throwable;

/**
 * Is a file excluded from indexing by a `rag-excluded` system tag on itself or any ancestor?
 * - Every system tag named `rag-excluded` counts, whatever its visibility flags (M5).
 * - Ancestors come from oc_filecache parent ids, to the storage root, for tag lookup only; never `permissions` (D1).
 * - Any failure (no such tag, tag service error, broken or too deep walk) throws ExclusionUnavailable: fail closed.
 */
class ExclusionChecker {
	public const TAG_NAME = 'rag-excluded';
	public const MAX_DEPTH = 64;

	public function __construct(
		private IDBConnection $db,
		private ISystemTagManager $tags,
		private ISystemTagObjectMapper $mapper,
	) {
	}

	/**
	 * @param list<int> $fileIds
	 * @param bool $brokenWalkIsExcluded true: a file whose ancestor walk fails or is cut short is reported as excluded
	 *        (the caller drops that hit) instead of failing the whole batch. Tag-service failures always throw.
	 * @return array<int, bool> file id => excluded
	 * @throws ExclusionUnavailable
	 */
	public function excludedAmong(array $fileIds, bool $brokenWalkIsExcluded = false): array {
		if ($fileIds === []) {
			return [];
		}
		try {
			$tagIds = $this->excludedTagIds();
			if ($tagIds === []) {
				throw new ExclusionUnavailable('No system tag named ' . self::TAG_NAME . ' exists; refusing to decide exclusion');
			}
			$chains = [];
			$out = [];
			$all = [];
			$parents = []; // folder memo, lives for this call only: hits in the same folders walk their ancestors once
			foreach ($fileIds as $id) {
				try {
					$chains[$id] = $this->chain($id, $parents);
				} catch (ExclusionUnavailable $e) {
					if (!$brokenWalkIsExcluded) {
						throw $e;
					}
					$out[$id] = true;
					continue;
				}
				foreach ($chains[$id] as $c) {
					$all[$c] = true;
				}
			}
			$tagged = $all === [] ? [] : $this->mapper->getTagIdsForObjects(array_map('strval', array_keys($all)), 'files');
			foreach ($chains as $id => $chain) {
				$out[$id] = false;
				foreach ($chain as $c) {
					if (array_intersect($tagIds, array_map('strval', $tagged[(string)$c] ?? [])) !== []) {
						$out[$id] = true;
						break;
					}
				}
			}
			return $out;
		} catch (ExclusionUnavailable $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new ExclusionUnavailable('Exclusion lookup failed: ' . get_class($e), 0, $e);
		}
	}

	public function isExcluded(int $fileId): bool {
		return $this->excludedAmong([$fileId])[$fileId];
	}

	/** @return list<string> */
	protected function excludedTagIds(): array {
		$ids = [];
		foreach ($this->tags->getAllTags(null, self::TAG_NAME) as $tag) {
			if ($tag->getName() === self::TAG_NAME) {
				$ids[] = (string)$tag->getId();
			}
		}
		return $ids;
	}

	/** @return list<int> the file id and its ancestors up to the storage root */
	protected function chain(int $fileId, array &$parents = []): array {
		$chain = [$fileId];
		$current = $fileId;
		for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
			$parent = array_key_exists($current, $parents) ? $parents[$current] : ($parents[$current] = $this->parentOf($current));
			if ($parent === null) {
				throw $current === $fileId
					? new FileGone("File $current has no filecache row")
					: new ExclusionUnavailable("Ancestor $current has no filecache row");
			}
			if ($parent <= 0 || $parent === $current) {
				return $chain;
			}
			$chain[] = $parent;
			$current = $parent;
		}
		throw new ExclusionUnavailable("Ancestor walk for file $fileId exceeded " . self::MAX_DEPTH . ' levels');
	}

	/** @return int|null parent id (<= 0 at a storage root), null when the row does not exist */
	protected function parentOf(int $fileId): ?int {
		$v = $this->db->executeQuery('SELECT parent FROM *PREFIX*filecache WHERE fileid = ?', [$fileId])->fetchOne();
		return $v === false ? null : (int)$v;
	}
}
