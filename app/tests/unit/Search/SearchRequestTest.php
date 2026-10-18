<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use OCA\BuscadorDocumental\Search\InvalidSearch;
use OCA\BuscadorDocumental\Search\SearchRequest;
use PHPUnit\Framework\TestCase;

class SearchRequestTest extends TestCase {
	public function testDefaults(): void {
		$r = SearchRequest::fromArray(['query' => '  hemoglobina  ']);
		$this->assertSame('hemoglobina', $r->query);
		$this->assertSame('exacta', $r->mode);
		$this->assertSame('relevance', $r->sort);
		$this->assertSame(25, $r->pageSize);
		$this->assertNull($r->cursor);
		$this->assertTrue($r->filters->isEmpty());
	}

	public function testEverythingIsReadFromTheBody(): void {
		$r = SearchRequest::fromArray([
			'query' => 'glucosa', 'mode' => 'mixta', 'sort' => 'name_desc', 'page_size' => 100, 'cursor' => 'abc.def',
			'filters' => ['types' => ['pdf', 'imagen'], 'modified_from' => '2026-01-01', 'modified_to' => '2026-01-31', 'folder_id' => 42],
		]);
		$this->assertSame(['mixta', 'name_desc', 100, 'abc.def'], [$r->mode, $r->sort, $r->pageSize, $r->cursor]);
		$this->assertSame(['application/pdf', 'image/jpeg', 'image/png', 'image/tiff'], $r->filters->mimes);
		$this->assertSame(gmmktime(0, 0, 0, 1, 1, 2026), $r->filters->from);
		$this->assertSame(gmmktime(23, 59, 59, 1, 31, 2026), $r->filters->to, 'the end date is inclusive');
		$this->assertSame(42, $r->filters->folderId);
	}

	public function testPropiedadAndUncertainAreRead(): void {
		$f = SearchRequest::fromArray(['query' => 'x', 'filters' => ['owner' => 'shared', 'uncertain' => true]])->filters;
		$this->assertSame(['shared', true], [$f->owner, $f->uncertain]);
		$this->assertFalse($f->isEmpty());
	}

	public function testAQueryIsCutToTheLimitByCharactersNotBytes(): void {
		$r = SearchRequest::fromArray(['query' => str_repeat('á', 900)]);
		$this->assertSame(SearchRequest::MAX_QUERY_CHARS, mb_strlen($r->query));
	}

	/** @dataProvider rejected */
	public function testBadInputIsRefusedWithASpanishMessage(array $body, string $needle): void {
		try {
			SearchRequest::fromArray($body);
			$this->fail('should have been refused');
		} catch (InvalidSearch $e) {
			$this->assertStringContainsString($needle, $e->getMessage());
		}
	}

	public static function rejected(): array {
		return [
			'query not a string' => [['query' => ['x']], 'consulta'],
			'mode' => [['query' => 'x', 'mode' => 'magia'], 'modo'],
			'sort' => [['query' => 'x', 'sort' => 'azar'], 'orden'],
			'page size' => [['query' => 'x', 'page_size' => 30], 'tamaño'],
			'page size string' => [['query' => 'x', 'page_size' => '25; DROP'], 'tamaño'],
			'cursor not a string' => [['query' => 'x', 'cursor' => 5], 'cursor'],
			'long cursor' => [['query' => 'x', 'cursor' => str_repeat('a', 600)], 'cursor'],
			'filters not an object' => [['query' => 'x', 'filters' => 'pdf'], 'filtro'],
			'unknown type' => [['query' => 'x', 'filters' => ['types' => ['exe']]], 'tipo'],
			'types not a list' => [['query' => 'x', 'filters' => ['types' => 'pdf']], 'tipo'],
			'bad date' => [['query' => 'x', 'filters' => ['modified_from' => '31/01/2026']], 'fecha'],
			'impossible date' => [['query' => 'x', 'filters' => ['modified_to' => '2026-02-31']], 'fecha'],
			'range reversed' => [['query' => 'x', 'filters' => ['modified_from' => '2026-02-01', 'modified_to' => '2026-01-01']], 'fecha'],
			'folder' => [['query' => 'x', 'filters' => ['folder_id' => -3]], 'carpeta'],
			'folder string' => [['query' => 'x', 'filters' => ['folder_id' => '5 OR 1=1']], 'carpeta'],
			'owner' => [['query' => 'x', 'filters' => ['owner' => 'bob']], 'propiedad'],
			'owner not a string' => [['query' => 'x', 'filters' => ['owner' => ['mine']]], 'propiedad'],
			'uncertain' => [['query' => 'x', 'filters' => ['uncertain' => 'yes']], 'incierta'],
			'unknown filter' => [['query' => 'x', 'filters' => ['owner' => 'bob']], 'filtro'],
		];
	}

	public function testABlankQueryIsValidAndEmpty(): void {
		$this->assertTrue(SearchRequest::fromArray(['query' => '   '])->isBlank());
		$this->assertFalse(SearchRequest::fromArray(['query' => 'a'])->isBlank());
	}
}
