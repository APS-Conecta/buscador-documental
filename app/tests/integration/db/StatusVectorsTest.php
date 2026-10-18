<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Status\StatusReport;

class StatusVectorsTest extends DbTestCase {
	private function snapshot(): array {
		return (new StatusReport(new IndexDb($this->connection($this->app()))))->snapshot();
	}

	public function testWithoutAProfileTheVectorSectionSaysSo(): void {
		$this->assertSame(['generations' => [], 'queued' => 0, 'failed' => 0], $this->snapshot()['vectors']);
	}

	public function testItReportsGenerationsCoverageBacklogAndFailures(): void {
		$a = $this->app();
		$a->exec("SELECT aps_search.create_generation(1, 'bge-m3', 'bge-m3:567m', 'sha256:abc', 3, 'f16', '', '', 8192)");
		$a->exec('SELECT aps_search.mark_generation_ready(1)');
		$a->exec('SELECT aps_search.activate_generation(1)');
		$a->exec("INSERT INTO aps_search.settings (key, value) VALUES ('vec_coverage_1', '0.4000')");
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, vec_state) VALUES (1, 'e', 'indexed', 'failed'), (2, 'e', 'indexed', 'pending')");
		$a->exec("INSERT INTO aps_search.job (lane, file_id, idem_key, gen) VALUES ('vec', 2, '2:e:1', 1)");
		$v = $this->snapshot()['vectors'];
		$this->assertSame([['gen' => 1, 'profile' => 'bge-m3', 'state' => 'active', 'coverage' => 0.4]], $v['generations']);
		$this->assertSame([1, 1], [$v['queued'], $v['failed']]);
		$this->assertStringNotContainsString('sha256', json_encode($v), 'no digest, no address');
	}

	public function testTextJobsAreNotCountedAsVectorBacklog(): void {
		$this->seedFile(1);
		$this->assertSame(0, $this->snapshot()['vectors']['queued']);
	}
}
