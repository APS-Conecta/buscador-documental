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
		$this->assertSame(1767236400, $r->filters->from);
		$this->assertSame(1769914799, $r->filters->to, 'the end date is inclusive');
		$this->assertSame(42, $r->filters->folderId);
	}

	/** @dataProvider santiagoDays */
	public function testDateFiltersAreSantiagoCalendarDays(string $key, string $day, int $bound, ?int $eveningEdit = null): void {
		$f = SearchRequest::fromArray(['query' => 'x', 'filters' => [$key => $day]])->filters;
		$actual = $key === 'modified_from' ? $f->from : $f->to;
		$this->assertSame($bound, $actual);
		if ($eveningEdit !== null) {
			$this->assertLessThanOrEqual($actual, $eveningEdit, 'an evening edit matches the day it is shown on');
		}
	}

	public static function santiagoDays(): array {
		return [
			'summer end: 2026-10-27 23:59:59 -03, includes a 22:00 edit' => ['modified_to', '2026-10-27', 1793156399, 1793149200],
			'summer start: 2026-10-28 00:00 -03' => ['modified_from', '2026-10-28', 1793156400],
			'winter end: 2026-06-15 23:59:59 -04, includes a 20:30 edit' => ['modified_to', '2026-06-15', 1781582399, 1781569800],
			'DST start has no midnight: 2026-09-06 01:00 -03' => ['modified_from', '2026-09-06', 1788667200],
			'DST start, 23-hour day: 2026-09-06 23:59:59 -03' => ['modified_to', '2026-09-06', 1788749999],
			'DST end, 25-hour day: 2026-04-04 23:59:59 -04' => ['modified_to', '2026-04-04', 1775361599],
		];
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
