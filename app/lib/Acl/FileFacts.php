<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Acl;

/** What the principal writer and the indexer need to know about one file, read from Nextcloud's tables. */
final class FileFacts {
	/**
	 * @param list<string> $shareUserIds users with a direct user share on the file or any ancestor
	 * @param list<string> $shareGroupIds groups with a group share on the file or any ancestor
	 * @param list<string> $groupfolderGroupIds groups granted on the groupfolder holding the file (empty outside groupfolders)
	 * @param int $storageId numeric id of the storage holding the file (erasure on user deletion finds files by it)
	 * @param bool $unmodelledAccess the file is reachable through something the prefilter cannot list: a circle, Talk, Deck, mail
	 *                               or federated share on it or an ancestor, or a groupfolder with advanced ACL
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly string $name,
		public readonly string $mime,
		public readonly int $mtime,
		public readonly string $etag,
		public readonly ?string $ownerUid,
		public readonly array $shareUserIds = [],
		public readonly array $shareGroupIds = [],
		public readonly array $groupfolderGroupIds = [],
		public readonly bool $unmodelledAccess = false,
		public readonly int $storageId = 0,
	) {
	}
}
