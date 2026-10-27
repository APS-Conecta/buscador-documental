<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use DateTimeImmutable;
use DateTimeZone;

/** A validated search request (S4 design section 3). Built only through fromArray(); everything else is typed. */
final class SearchRequest {
	public const MAX_QUERY_CHARS = 500;
	public const MODES = ['exacta', 'significado', 'mixta'];
	public const SORTS = ['relevance', 'modified_desc', 'modified_asc', 'name_asc', 'name_desc'];
	public const PAGE_SIZES = [25, 50, 100];
	private const FILTER_KEYS = ['types', 'modified_from', 'modified_to', 'folder_id', 'owner', 'uncertain'];
	// The zone the client shows result dates in (app/src/lib/format.js); a constant, not IDateTimeZone, because the instalador leaves Nextcloud's default zone at UTC (totem#7).
	private const TIME_ZONE = 'America/Santiago';

	private function __construct(
		public readonly string $query,
		public readonly string $mode,
		public readonly string $sort,
		public readonly int $pageSize,
		public readonly ?string $cursor,
		public readonly Filters $filters,
	) {
	}

	/** @param array<string, mixed> $in @throws InvalidSearch */
	public static function fromArray(array $in): self {
		$query = $in['query'] ?? '';
		if (!is_string($query)) {
			throw new InvalidSearch('La consulta debe ser un texto.');
		}
		$mode = $in['mode'] ?? 'exacta';
		if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
			throw new InvalidSearch('El modo de búsqueda no es válido.');
		}
		$sort = $in['sort'] ?? 'relevance';
		if (!is_string($sort) || !in_array($sort, self::SORTS, true)) {
			throw new InvalidSearch('El orden no es válido.');
		}
		$size = $in['page_size'] ?? 25;
		if (!is_int($size) || !in_array($size, self::PAGE_SIZES, true)) {
			throw new InvalidSearch('El tamaño de página debe ser 25, 50 o 100.');
		}
		$cursor = $in['cursor'] ?? null;
		if ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 512)) {
			throw new InvalidSearch('El cursor de paginación no es válido.');
		}
		return new self(mb_substr(trim($query), 0, self::MAX_QUERY_CHARS), $mode, $sort, $size, $cursor, self::filters($in['filters'] ?? []));
	}

	public function isBlank(): bool {
		return $this->query === '';
	}

	private static function filters(mixed $f): Filters {
		if (!is_array($f) || ($f !== [] && array_is_list($f))) {
			throw new InvalidSearch('Los filtros no tienen el formato esperado.');
		}
		foreach (array_keys($f) as $k) {
			if (!in_array($k, self::FILTER_KEYS, true)) {
				throw new InvalidSearch('Hay un filtro desconocido.');
			}
		}
		$mimes = [];
		if (array_key_exists('types', $f)) {
			if (!is_array($f['types']) || !array_is_list($f['types'])) {
				throw new InvalidSearch('El filtro de tipo de archivo no es válido.');
			}
			try {
				$mimes = MimeGroups::mimes($f['types']);
			} catch (\InvalidArgumentException) {
				throw new InvalidSearch('El tipo de archivo del filtro no es válido.');
			}
		}
		$from = array_key_exists('modified_from', $f) ? self::day($f['modified_from'], false) : null;
		$to = array_key_exists('modified_to', $f) ? self::day($f['modified_to'], true) : null;
		if ($from !== null && $to !== null && $from > $to) {
			throw new InvalidSearch('La fecha inicial es posterior a la final.');
		}
		$folder = null;
		if (array_key_exists('folder_id', $f)) {
			if (!is_int($f['folder_id']) || $f['folder_id'] < 1) {
				throw new InvalidSearch('La carpeta del filtro no es válida.');
			}
			$folder = $f['folder_id'];
		}
		$owner = null;
		if (array_key_exists('owner', $f)) {
			if (!in_array($f['owner'], ['mine', 'shared'], true)) {
				throw new InvalidSearch('El filtro de propiedad debe ser «mis archivos» o «compartidos conmigo».');
			}
			$owner = $f['owner'];
		}
		$uncertain = false;
		if (array_key_exists('uncertain', $f)) {
			if (!is_bool($f['uncertain'])) {
				throw new InvalidSearch('El filtro de lectura incierta no es válido.');
			}
			$uncertain = $f['uncertain'];
		}
		return new Filters($mimes, $from, $to, $folder, $owner, $uncertain);
	}

	private static function day(mixed $v, bool $endOfDay): int {
		if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) !== 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new InvalidSearch('La fecha debe tener el formato AAAA-MM-DD.');
		}
		$start = new DateTimeImmutable($v, new DateTimeZone(self::TIME_ZONE));
		return $endOfDay ? $start->modify('tomorrow')->getTimestamp() - 1 : $start->getTimestamp();
	}
}
