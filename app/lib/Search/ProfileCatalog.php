<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use RuntimeException;

/** The shipped list of embedding profiles (profiles.json). A profile names an Ollama model tag, never a download. */
final class ProfileCatalog {
	/** @param array<string, array{id: string, model: string, dim: int, quant: ?string, query_prefix: string, passage_prefix: string, max_tokens: int}> $profiles */
	private function __construct(private array $profiles) {
	}

	public static function shipped(): self {
		return self::fromJson((string)@file_get_contents(__DIR__ . '/profiles.json'));
	}

	public static function fromJson(string $json): self {
		$data = json_decode($json, true);
		if (!is_array($data) || !is_array($data['profiles'] ?? null)) {
			throw new RuntimeException('The profile catalogue is missing or malformed');
		}
		$out = [];
		foreach ($data['profiles'] as $p) {
			if (!is_array($p) || !is_string($p['id'] ?? null) || !is_string($p['model'] ?? null) || !is_int($p['dim'] ?? null)
				|| $p['dim'] < 1 || $p['dim'] > 4000 || !is_string($p['query_prefix'] ?? null) || !is_string($p['passage_prefix'] ?? null)
				|| !is_int($p['max_tokens'] ?? null) || $p['max_tokens'] < 1 || isset($out[$p['id']])) {
				throw new RuntimeException('The profile catalogue has an invalid entry');
			}
			$out[$p['id']] = ['id' => $p['id'], 'model' => $p['model'], 'dim' => $p['dim'], 'quant' => is_string($p['quant'] ?? null) ? $p['quant'] : null,
				'query_prefix' => $p['query_prefix'], 'passage_prefix' => $p['passage_prefix'], 'max_tokens' => $p['max_tokens']];
		}
		return new self($out);
	}

	/** @return array<string, array{id: string, model: string, dim: int, quant: ?string, query_prefix: string, passage_prefix: string, max_tokens: int}> */
	public function all(): array {
		return $this->profiles;
	}

	/** @return array{id: string, model: string, dim: int, quant: ?string, query_prefix: string, passage_prefix: string, max_tokens: int}|null */
	public function find(string $id): ?array {
		return $this->profiles[$id] ?? null;
	}
}
