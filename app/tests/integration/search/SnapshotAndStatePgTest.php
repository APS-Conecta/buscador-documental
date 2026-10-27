<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\search;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Search\SnapshotStore;
use OCA\BuscadorDocumental\Search\VectorState;
use OCA\BuscadorDocumental\Tests\integration\db\DbTestCase;

class SnapshotAndStatePgTest extends DbTestCase {
	private function db(): IndexDb {
		return new IndexDb($this->connection($this->app()));
	}

	public function testASnapshotRoundTripsIdsInOrderForItsOwnerOnly(): void {
		$s = new SnapshotStore($this->db());
		$qid = $s->save('alice', [30, 10, 20], [301, 101, 201], false);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $qid);
		$this->assertSame(['file_ids' => [30, 10, 20], 'chunk_ids' => [301, 101, 201], 'capped' => false], $s->load($qid, 'alice'));
		$this->assertNull($s->load($qid, 'bob'), 'a snapshot belongs to the user who built it');
		$this->assertNull($s->load('00000000-0000-4000-8000-000000000000', 'alice'));
		$this->assertNull($s->load('not a uuid', 'alice'));
	}

	public function testASnapshotKeepsWhetherItsListIsIncomplete(): void {
		$s = new SnapshotStore($this->db());
		$this->assertTrue($s->load($s->save('alice', [1], [2], true), 'alice')['capped']);
		$this->assertFalse($s->load($s->save('alice', [1], [2], false), 'alice')['capped']);
	}

	public function testTheSnapshotHoldsOnlyIdsAndAHashedUser(): void {
		(new SnapshotStore($this->db()))->save('alice', [1], [2], false);
		$row = $this->app()->query('SELECT * FROM aps_search.search_snapshot')->fetch(\PDO::FETCH_ASSOC);
		$this->assertStringNotContainsString('alice', json_encode($row));
		$this->assertSame(['qid', 'uid_hash', 'created_at', 'file_ids', 'chunk_ids', 'ver', 'capped'], array_keys($row));
	}

	public function testAnExpiredSnapshotIsGoneAndOldOnesArePruned(): void {
		$s = new SnapshotStore($this->db());
		$qid = $s->save('alice', [1], [2], false);
		$this->app()->exec("UPDATE aps_search.search_snapshot SET created_at = now() - interval '11 minutes'");
		$this->assertNull($s->load($qid, 'alice'));
		$this->app()->exec("UPDATE aps_search.search_snapshot SET created_at = now() - interval '25 minutes'");
		$s->save('alice', [3], [4], false);
		$this->assertSame(1, (int)$this->app()->query('SELECT count(*) FROM aps_search.search_snapshot')->fetchColumn(), 'saving prunes what is long dead');
	}

	public function testOneUserCannotPileUpSnapshots(): void {
		$s = new SnapshotStore($this->db());
		$ids = [];
		for ($i = 0; $i < SnapshotStore::PER_USER + 5; $i++) {
			$ids[] = $s->save('alice', [$i], [$i], false);
		}
		$s->save('bob', [1], [1], false);
		$this->assertSame(SnapshotStore::PER_USER, (int)$this->app()->query("SELECT count(*) FROM aps_search.search_snapshot WHERE uid_hash = '" . \OCA\BuscadorDocumental\Search\Cursor::uidHash('alice') . "'")->fetchColumn());
		$this->assertNull($s->load($ids[0], 'alice'), 'the oldest went');
		$this->assertNotNull($s->load(end($ids), 'alice'), 'the newest stayed');
		$this->assertSame(1, (int)$this->app()->query("SELECT count(*) FROM aps_search.search_snapshot WHERE uid_hash = '" . \OCA\BuscadorDocumental\Search\Cursor::uidHash('bob') . "'")->fetchColumn());
	}

	public function testAnEmptyListIsAValidSnapshot(): void {
		$s = new SnapshotStore($this->db());
		$this->assertSame(['file_ids' => [], 'chunk_ids' => [], 'capped' => false], $s->load($s->save('alice', [], [], false), 'alice'));
	}

	public function testSemanticSearchIsOffUntilProfileValidationAndCoverageAllowIt(): void {
		$db = $this->db();
		$settings = new Settings($db);
		$state = new VectorState($db, $settings);
		$this->assertSame(['semantic' => false, 'reason' => 'no_profile', 'coverage' => 0.0, 'gen' => null], $state->availability());

		$app = $this->app();
		$app->exec("SELECT aps_search.create_generation(1, 'bge-m3', 'bge-m3:567m', 'sha256:abc', 3, 'f16', 'query: ', 'passage: ', 8192)");
		$app->exec('SELECT aps_search.mark_generation_ready(1)');
		$app->exec('SELECT aps_search.activate_generation(1)');
		$this->assertSame('not_validated', $state->availability()['reason']);

		$settings->set('semantic_enabled', '1');
		$settings->set('vec_coverage', '0.5');
		$a = $state->availability();
		$this->assertSame([false, 'coverage', 0.5, 1], [$a['semantic'], $a['reason'], $a['coverage'], $a['gen']]);

		$settings->set('vec_coverage', '0.96');
		$this->assertSame(['semantic' => true, 'reason' => null, 'coverage' => 0.96, 'gen' => 1], $state->availability());

		$settings->set('semantic_min_coverage', '0.99');
		$this->assertFalse($state->availability()['semantic']);
	}

	public function testTheActiveProfileCarriesWhatTheQuerySideNeeds(): void {
		$db = $this->db();
		$state = new VectorState($db, new Settings($db));
		$this->assertNull($state->activeProfile());
		$app = $this->app();
		$app->exec("SELECT aps_search.create_generation(2, 'bge-m3', 'bge-m3:567m', 'sha256:abc', 3, 'f16', 'query: ', 'passage: ', 8192)");
		$app->exec('SELECT aps_search.mark_generation_ready(2)');
		$app->exec('SELECT aps_search.activate_generation(2)');
		$this->assertSame(['gen' => 2, 'model' => 'bge-m3:567m', 'digest' => 'sha256:abc', 'dim' => 3, 'query_prefix' => 'query: ', 'max_tokens' => 8192], $state->activeProfile());
	}

	public function testTunablesHaveDefaultsAndIgnoreJunk(): void {
		$db = $this->db();
		$settings = new Settings($db);
		$state = new VectorState($db, $settings);
		$this->assertSame([2000, 1500], [$state->exactScanFiles(), $state->embedTimeoutMs()]);
		$settings->set('exact_scan_files', '500');
		$settings->set('embed_timeout_ms', '800');
		$this->assertSame([500, 800], [$state->exactScanFiles(), $state->embedTimeoutMs()]);
		$settings->set('exact_scan_files', 'many');
		$settings->set('embed_timeout_ms', '-5');
		$this->assertSame([2000, 1500], [$state->exactScanFiles(), $state->embedTimeoutMs()]);
	}
}
