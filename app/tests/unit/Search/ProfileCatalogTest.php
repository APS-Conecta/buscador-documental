<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use OCA\BuscadorDocumental\Search\ProfileCatalog;
use PHPUnit\Framework\TestCase;

class ProfileCatalogTest extends TestCase {
	public function testTheShippedCatalogueHasTheTwoCheckedProfiles(): void {
		$c = ProfileCatalog::shipped();
		$this->assertSame(['bge-m3', 'qwen3-embedding-0.6b'], array_keys($c->all()));
		$p = $c->find('bge-m3');
		$this->assertSame(['bge-m3:567m', 1024, '', '', 8192], [$p['model'], $p['dim'], $p['query_prefix'], $p['passage_prefix'], $p['max_tokens']]);
		$this->assertStringContainsString('Query:', $c->find('qwen3-embedding-0.6b')['query_prefix']);
		$this->assertNull($c->find('nope'));
	}

	public function testEveryShippedProfileIsComplete(): void {
		foreach (ProfileCatalog::shipped()->all() as $id => $p) {
			$this->assertSame($id, $p['id']);
			$this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9._:-]*:[a-z0-9._-]+$/', $p['model'], "$id pins an explicit tag, not latest");
			$this->assertStringNotContainsString(':latest', $p['model']);
			$this->assertGreaterThan(0, $p['dim']);
			$this->assertLessThanOrEqual(4000, $p['dim']);
			$this->assertGreaterThanOrEqual(512, $p['max_tokens']);
		}
	}

	public function testABrokenCatalogueIsRefused(): void {
		foreach (['{', '{"profiles": 5}', '{"profiles": [{"id": "x"}]}', '{"profiles": [{"id":"a","model":"m:1","dim":0,"query_prefix":"","passage_prefix":"","max_tokens":512}]}',
			'{"profiles": [{"id":"a","model":"m:1","dim":3,"query_prefix":"","passage_prefix":"","max_tokens":512},{"id":"a","model":"m:2","dim":3,"query_prefix":"","passage_prefix":"","max_tokens":512}]}'] as $json) {
			try {
				ProfileCatalog::fromJson($json);
				$this->fail("accepted: $json");
			} catch (\RuntimeException) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
