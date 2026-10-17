<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use InvalidArgumentException;

/** The file-type filter: user-facing group names (Spanish API values) and the mime types they stand for. */
final class MimeGroups {
	public const GROUPS = [
		'pdf' => ['application/pdf'],
		'word' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text'],
		'hoja' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
		'presentacion' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
		'texto' => ['text/plain', 'text/markdown'],
		'imagen' => ['image/jpeg', 'image/png', 'image/tiff'],
	];

	/**
	 * @param list<string> $groups
	 * @return list<string> sorted, unique mime types
	 * @throws InvalidArgumentException for a name that is not a group
	 */
	public static function mimes(array $groups): array {
		$out = [];
		foreach ($groups as $g) {
			if (!is_string($g) || !isset(self::GROUPS[$g])) {
				throw new InvalidArgumentException('unknown file type group');
			}
			array_push($out, ...self::GROUPS[$g]);
		}
		$out = array_values(array_unique($out));
		sort($out);
		return $out;
	}
}
