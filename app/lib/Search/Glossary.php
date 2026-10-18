<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use RuntimeException;

/**
 * Query-time expansion of the APS acronym dictionary (S4 design section 4). For a query it returns extra plain phrases; the
 * caller hands each to websearch_to_tsquery and ORs the result with the user's own query, so no tsquery operator text is ever
 * built here. Ambiguous keys are never expanded and an ambiguous key's expansion never adds the key.
 */
final class Glossary {
	public const MAX_ALTERNATIVES = 5;
	private const FOLD = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
		'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'û' => 'u', 'ç' => 'c'];

	/** @var array<string, list<array{0: list<string>, 1: string}>> first token of a key => [key tokens, expansion] */
	private array $byKey = [];
	/** @var array<string, list<array{0: list<string>, 1: string}>> first token of an expansion => [expansion tokens, key] */
	private array $byExpansion = [];

	/** @param list<array{key: string, expansion: string, ambiguous: bool}> $entries */
	public function __construct(array $entries) {
		$ambiguousKeys = [];
		$ambiguousExpansions = [];
		foreach ($entries as $e) {
			if ($e['ambiguous']) {
				$ambiguousKeys[$e['key']] = true;
				$ambiguousExpansions[$e['expansion']] = true;
			}
		}
		foreach ($entries as $e) {
			if ($e['ambiguous'] || isset($ambiguousKeys[$e['key']])) {
				continue;
			}
			$k = explode(' ', $e['key']);
			$x = explode(' ', $e['expansion']);
			$this->byKey[$k[0]][] = [$k, $e['expansion']];
			// A one-word expansion ("enfermera") is an ordinary word: typing it must not add the acronym back.
			if (count($x) >= 2 && !isset($ambiguousExpansions[$e['expansion']])) {
				$this->byExpansion[$x[0]][] = [$x, $e['key']];
			}
		}
	}

	public static function fromFile(string $path): self {
		$raw = @file_get_contents($path);
		$data = $raw === false ? null : json_decode($raw, true);
		if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
			throw new RuntimeException('The glossary file is missing or malformed');
		}
		return new self($data['entries']);
	}

	/** Lower case, accents removed, anything that is not a letter or digit becomes a single space: the index's own folding. */
	public static function fold(string $text): string {
		$text = strtr(mb_strtolower($text, 'UTF-8'), self::FOLD);
		$text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
		return trim($text);
	}

	/** @return list<string> at most MAX_ALTERNATIVES folded phrases, in query order */
	public function alternatives(string $query): array {
		$folded = self::fold($query);
		if ($folded === '') {
			return [];
		}
		$tokens = explode(' ', $folded);
		$out = [];
		foreach ($tokens as $i => $t) {
			foreach ([$this->byKey, $this->byExpansion] as $index) {
				foreach ($index[$t] ?? [] as [$seq, $add]) {
					if (array_slice($tokens, $i, count($seq)) === $seq && $add !== $folded) {
						$out[$add] = true;
					}
				}
			}
			if (count($out) >= self::MAX_ALTERNATIVES) {
				break;
			}
		}
		return array_slice(array_keys($out), 0, self::MAX_ALTERNATIVES);
	}
}
