<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\search;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Search\HitBuilder;
use OCA\BuscadorDocumental\Search\RecheckResult;
use OCA\BuscadorDocumental\Tests\integration\db\DbTestCase;
use OCP\Files\Node;

class HitBuilderPgTest extends DbTestCase {
	private function recheckResult(int $file): RecheckResult {
		$n = $this->createMock(Node::class);
		$n->method('getName')->willReturn("f$file.pdf");
		$n->method('getEtag')->willReturn('e1');
		return new RecheckResult($file, $n, true);
	}

	public function testAChunkOfAnotherFileNeverBecomesThisFilesSnippet(): void {
		$a = $this->app();
		$mine = (int)$a->query("INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES (1, 1, 0, 'texto mío') RETURNING chunk_id")->fetchColumn();
		$theirs = (int)$a->query("INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES (2, 1, 0, 'texto ajeno confidencial') RETURNING chunk_id")->fetchColumn();
		$b = new HitBuilder(new IndexDb($this->connection($a)));
		$hits = $b->build('texto', [], [[['chunk_id' => $theirs, 'etag' => 'e1'], $this->recheckResult(1)], [['chunk_id' => $mine, 'etag' => 'e1'], $this->recheckResult(1)]]);
		$this->assertSame([['file_id' => 1, 'title' => 'f1.pdf']], [array_intersect_key($hits[0], ['file_id' => 1, 'title' => 1])]);
		$this->assertArrayNotHasKey('snippet', $hits[0], 'the chunk belongs to file 2');
		$this->assertStringContainsString('mío', $hits[1]['snippet']);
	}
}
