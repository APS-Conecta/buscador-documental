<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Search\CandidateQuery;
use OCA\BuscadorDocumental\Search\EmbedClient;
use OCA\BuscadorDocumental\Search\ProbeFailed;
use OCA\BuscadorDocumental\Search\ProfileCatalog;
use OCA\BuscadorDocumental\Search\ProfileException;
use OCA\BuscadorDocumental\Search\ProfileManager;
use OCP\IAppConfig;
use PDO;

/** `occ buscador:profile`: setting up, activating and dropping embedding generations. */
class ProfileManagerTest extends DbTestCase {
	/** @var array<string, string> */
	private array $config = [];
	private array|ProbeFailed $probe = ['digest' => 'sha256:aaa', 'dim' => 1024];

	private function manager(?PDO $session = null): ProfileManager {
		$db = new IndexDb($this->connection($session ?? $this->app()));
		$app = $this->createMock(IAppConfig::class);
		$app->method('getValueString')->willReturnCallback(fn (string $a, string $k, string $d = '') => $this->config[$k] ?? $d);
		$app->method('setValueString')->willReturnCallback(function (string $a, string $k, string $v, bool $lazy = false, bool $sensitive = false): bool {
			$this->config[$k] = $v;
			$this->config["$k.sensitive"] = $sensitive ? '1' : '0';
			return true;
		});
		$app->method('deleteKey')->willReturnCallback(function (string $a, string $k): void {
			unset($this->config[$k], $this->config["$k.sensitive"]);
		});
		$embed = $this->createMock(EmbedClient::class);
		$embed->method('inspect')->willReturnCallback(function () {
			if ($this->probe instanceof ProbeFailed) {
				throw $this->probe;
			}
			return $this->probe;
		});
		return new ProfileManager($db, ProfileCatalog::shipped(), $embed, $app, new Settings($db), new AuditLog($db, new Pseudonyms($db)), new CandidateQuery($db));
	}

	private function readyGeneration(int $gen): void {
		$a = $this->app();
		$a->exec("SELECT aps_search.create_generation($gen, 'bge-m3', 'bge-m3:567m', 'sha256:g$gen', 3, 'f16', '', '', 8192)");
		$a->exec("SELECT aps_search.mark_generation_ready($gen)");
	}

	private function states(): array {
		return $this->app()->query('SELECT gen || \':\' || state FROM aps_search.embedding_profile ORDER BY gen')->fetchAll(\PDO::FETCH_COLUMN);
	}

	private function refuses(callable $fn, string $needle): void {
		try {
			$fn();
			$this->fail("should refuse: $needle");
		} catch (ProfileException $e) {
			$this->assertStringContainsString($needle, $e->getMessage());
		}
	}

	public function testSetRecordsTheProbedModelAndStartsGenerationOne(): void {
		$r = $this->manager()->set('bge-m3', 'http://ollama:11434', 's3cr3t');
		$this->assertSame(['gen' => 1, 'digest' => 'sha256:aaa', 'dim' => 1024, 'model' => 'bge-m3:567m', 'warnings' => []], $r);
		$this->assertSame(['1:building'], $this->states());
		$this->assertSame('http://ollama:11434', $this->config['embed_url']);
		$this->assertSame(['s3cr3t', '1'], [$this->config['embed_token'], $this->config['embed_token.sensitive']], 'the token is stored as a sensitive value');
		$this->assertSame('1024', (string)$this->app()->query('SELECT dim FROM aps_search.embedding_profile WHERE gen = 1')->fetchColumn());
	}

	public function testPlainHttpBeyondTheMachineIsWarnedAbout(): void {
		$cases = [
			'http://ollama.clinic.lan:11434' => true, 'http://10.0.0.5:11434' => true, 'https://ollama.clinic.lan' => false,
			'http://127.0.0.1:11434' => false, 'http://localhost:11434' => false, 'http://[::1]:11434' => false, 'http://ollama:11434' => false,
		];
		$gen = 0;
		foreach ($cases as $url => $warns) {
			$this->probe = ['digest' => 'sha256:d' . ++$gen, 'dim' => 1024];
			$w = $this->manager()->set('bge-m3', $url, null)['warnings'];
			$this->assertSame($warns, $w !== [], $url);
			if ($warns) {
				$this->assertStringContainsString('unencrypted', $w[0]);
			}
		}
	}

	public function testItIsAuditedWithoutTheAddressOrTheToken(): void {
		$this->manager()->set('bge-m3', 'http://ollama:11434', 's3cr3t');
		$row = $this->app()->query("SELECT action, detail::text FROM aps_search.audit_log")->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame('profile.set', $row['action']);
		$this->assertSame(['gen' => 1, 'profile' => 'bge-m3'], json_decode($row['detail'], true));
	}

	public function testTheTokenIsKeptWhenNoneIsGivenAndClearedByAnEmptyOne(): void {
		$m = $this->manager();
		$m->set('bge-m3', 'http://ollama:11434', 'first');
		$this->probe = ['digest' => 'sha256:bbb', 'dim' => 1024];
		$m->set('bge-m3', 'http://ollama:11434', null);
		$this->assertSame('first', $this->config['embed_token']);
		$this->probe = ['digest' => 'sha256:ccc', 'dim' => 1024];
		$m->set('bge-m3', 'http://ollama:11434', '');
		$this->assertSame('', $this->config['embed_token']);
	}

	public function testBadSetupsAreRefusedAndLeaveNothingBehind(): void {
		$m = $this->manager();
		$this->refuses(fn () => $m->set('no-such', 'http://ollama:11434', null), 'Unknown profile');
		$this->refuses(fn () => $m->set('bge-m3', 'ftp://x', null), 'http or https');
		$this->refuses(fn () => $m->set('bge-m3', 'http://u:p@host', null), 'user name or password');
		$this->probe = new ProbeFailed('model_not_found');
		$this->refuses(fn () => $m->set('bge-m3', 'http://ollama:11434', 'tok'), 'ollama pull bge-m3:567m');
		$this->probe = new ProbeFailed('unreachable');
		$this->refuses(fn () => $m->set('bge-m3', 'http://ollama:11434', 'tok'), 'reach');
		$this->probe = ['digest' => 'sha256:aaa', 'dim' => 768];
		$this->refuses(fn () => $m->set('bge-m3', 'http://ollama:11434', 'tok'), 'dimension');
		$this->assertSame([], $this->states());
		$this->assertArrayNotHasKey('embed_url', $this->config, 'a failed setup restores the previous settings');
		$this->assertArrayNotHasKey('embed_token', $this->config);
	}

	public function testAFailedSetupPutsTheOldEndpointBack(): void {
		$m = $this->manager();
		$m->set('bge-m3', 'http://old:11434', 'old-token');
		$this->probe = new ProbeFailed('unreachable');
		$this->refuses(fn () => $m->set('bge-m3', 'http://new:11434', 'new-token'), 'reach');
		$this->assertSame(['http://old:11434', 'old-token'], [$this->config['embed_url'], $this->config['embed_token']]);
	}

	public function testTheSameModelAndDigestIsNotBuiltTwice(): void {
		$m = $this->manager();
		$m->set('bge-m3', 'http://ollama:11434', null);
		$this->refuses(fn () => $m->set('bge-m3', 'http://ollama:11434', null), 'Generation 1 already');
		$this->probe = ['digest' => 'sha256:new', 'dim' => 1024];
		$this->assertSame(2, $m->set('bge-m3', 'http://ollama:11434', null)['gen'], 'a changed digest is a new generation');
	}

	public function testMissingPgvectorIsNamedBeforeAnythingIsTouched(): void {
		$name = 'bd_t_nv_' . bin2hex(random_bytes(3));
		self::admin('nextcloud')->exec("CREATE DATABASE $name OWNER nextcloud");
		try {
			self::connect('postgres', 'dev', $name)->exec('CREATE EXTENSION unaccent');
			$app = self::connect('nextcloud', 'dev', $name);
			\OCA\BuscadorDocumental\Migration\SqlScript::runAtomically($this->connection($app), \OCA\BuscadorDocumental\Migration\SqlScript::up());
			$db = new IndexDb($this->connection($app));
			$m = new ProfileManager($db, ProfileCatalog::shipped(), $this->createMock(EmbedClient::class), $this->createMock(IAppConfig::class), new Settings($db), new AuditLog($db, new Pseudonyms($db)), new CandidateQuery($db));
			$this->refuses(fn () => $m->set('bge-m3', 'http://ollama:11434', null), 'CREATE EXTENSION vector');
		} finally {
			self::admin('nextcloud')->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
		}
	}

	public function testActivationNeedsAReadyGenerationAndMovesTheCoverageAcross(): void {
		$m = $this->manager();
		$m->set('bge-m3', 'http://ollama:11434', null);
		$this->refuses(fn () => $m->activate(1), 'not ready');
		$this->refuses(fn () => $m->activate(9), 'Unknown generation');
		$this->app()->exec('SELECT aps_search.mark_generation_ready(1)');
		(new Settings(new IndexDb($this->connection($this->app()))))->set('vec_coverage_1', '0.9700');
		$this->candidates()->buildIndex(1);
		$m->activate(1);
		$this->assertSame(['1:active'], $this->states());
		$this->assertSame('0.9700', $this->app()->query("SELECT value FROM aps_search.settings WHERE key = 'vec_coverage'")->fetchColumn());
		$this->assertSame('profile.activate', $this->app()->query("SELECT action FROM aps_search.audit_log ORDER BY id DESC LIMIT 1")->fetchColumn());
	}

	public function testDropRefusesTheActiveGenerationAndForgetsTheRest(): void {
		$m = $this->manager();
		$m->set('bge-m3', 'http://ollama:11434', null);
		$this->app()->exec('SELECT aps_search.mark_generation_ready(1)');
		$this->candidates()->buildIndex(1);
		$m->activate(1);
		$this->probe = ['digest' => 'sha256:two', 'dim' => 1024];
		$m->set('bge-m3', 'http://ollama:11434', null);
		$this->refuses(fn () => $m->drop(1), 'active');
		$m->drop(2);
		$this->assertSame(['1:active'], $this->states());
		$this->assertNull((new Settings(new IndexDb($this->connection($this->app()))))->get('vec_coverage_2'));
	}

	/** A generation of three-dimensional vectors, as `set` creates it once the probe passed. */
	private function generation(int $gen, string $state = 'building'): void {
		$a = $this->app();
		$a->exec("SELECT aps_search.create_generation($gen, 'bge-m3', 'bge-m3:567m', 'sha256:g$gen', 3, 'f16', '', '', 8192)");
		if ($state !== 'building') {
			$a->exec("SELECT aps_search.mark_generation_ready($gen)");
		}
		if ($state === 'active') {
			$a->exec("SELECT aps_search.activate_generation($gen)");
		}
	}

	/** An indexed file with one chunk, embedded in the given generations. */
	private function indexedFile(int $id, int ...$gens): void {
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state) VALUES ($id, 'e1', 'indexed')");
		$a->exec("INSERT INTO aps_search.chunk (file_id, page_no, ord, text) VALUES ($id, 1, 0, 't')");
		foreach ($gens as $g) {
			$this->embedIn($g, $id);
		}
	}

	private function embedIn(int $gen, int $id): void {
		$this->app()->exec("INSERT INTO aps_search.chunk_vec_$gen (chunk_id, file_id, vec) SELECT chunk_id, file_id, '[1,0,0]' FROM aps_search.chunk WHERE file_id = $id");
	}

	private function vecState(int $id): string {
		return (string)$this->app()->query("SELECT vec_state FROM aps_search.file_state WHERE file_id = $id")->fetchColumn();
	}

	public function testActivationBringsVecStateInLineWithTheNewGenerationsFailures(): void {
		[$a, $b, $c, $d] = [1, 2, 3, 4];
		$this->generation(1, 'active');
		$this->indexedFile($a, 1);
		$this->app()->exec("UPDATE aps_search.file_state SET vec_state = 'embedded' WHERE file_id = $a");
		foreach ([$b, $c, $d] as $id) {
			$this->indexedFile($id);
		}
		$this->app()->exec('SELECT aps_search.enqueue_vec_backfill(1, 10)');
		foreach ([$b, $c, $d] as $_) {
			$this->giveUpVecJob($this->claim($this->worker(), 'vec-w', lane: 'vec'));     // generation 1 gives up on B, C and D
		}
		$this->app()->exec("SELECT aps_search.erase_files(ARRAY[$d]::bigint[], 'excluded')");     // D: no chunks left, vec_state stays failed
		$this->generation(2);
		$this->embedIn(2, $b);
		$this->app()->exec('SELECT aps_search.enqueue_vec_backfill(2, 1)');
		$claim = $this->claim($this->worker(), 'vec-w', lane: 'vec');
		$this->assertSame($a, $claim['file_id']);
		$this->giveUpVecJob($claim);                                        // generation 2 gives up on A
		$this->app()->exec('SELECT aps_search.mark_generation_ready(2)');
		$this->candidates()->buildIndex(2);
		$this->manager()->activate(2);
		$this->assertSame(['failed', 'embedded', 'pending', 'failed'], array_map($this->vecState(...), [$a, $b, $c, $d]),
			'A failed in generation 2; B and C failed only in generation 1 (B has its vectors, C not yet); D is excluded');
	}

	public function testDroppingAGenerationForgetsItsFailures(): void {
		[$f, $g] = [1, 2];
		$this->generation(1, 'active');
		$this->indexedFile($f, 1);
		$this->indexedFile($g);
		$this->app()->exec('SELECT aps_search.enqueue_vec_backfill(1, 10)');
		$this->giveUpVecJob($this->claim($this->worker(), 'vec-w', lane: 'vec'));     // generation 1 gives up on G
		$this->generation(2);
		$this->app()->exec('SELECT aps_search.enqueue_vec_backfill(2, 1)');
		$this->giveUpVecJob($this->claim($this->worker(), 'vec-w', lane: 'vec'));     // generation 2 gives up on F
		$m = $this->manager();
		$m->drop(2);
		$this->assertSame(2, $m->set('bge-m3', 'http://ollama:11434', null)['gen'], 'the number is free again');
		$this->assertSame(2, (int)$this->app()->query('SELECT aps_search.enqueue_vec_backfill(2, 10)')->fetchColumn(), 'the new generation 2 tries F and G');
		$this->assertSame(0, (int)$this->app()->query('SELECT aps_search.enqueue_vec_backfill(1, 10)')->fetchColumn(), 'generation 1 still remembers G');
	}

	public function testSemanticSwitchNeedsAnActiveProfile(): void {
		$m = $this->manager();
		$this->refuses(fn () => $m->semantic(true), 'active profile');
		$m->set('bge-m3', 'http://ollama:11434', null);
		$this->app()->exec('SELECT aps_search.mark_generation_ready(1)');
		$this->candidates()->buildIndex(1);
		$m->activate(1);
		$m->semantic(true);
		$this->assertSame('1', $this->app()->query("SELECT value FROM aps_search.settings WHERE key = 'semantic_enabled'")->fetchColumn());
		$m->semantic(false);
		$this->assertSame('0', $this->app()->query("SELECT value FROM aps_search.settings WHERE key = 'semantic_enabled'")->fetchColumn());
	}

	public function testOneGenerationsFailedIndexBuildDoesNotStopTheOthers(): void {
		$this->readyGeneration(1);
		$this->readyGeneration(2);
		$su = self::connect('postgres', 'dev', self::$dbName);
		$su->exec('CREATE SEQUENCE aps_search.chunk_vec_1_hnsw');               // takes the index's name, so generation 1 cannot build
		try {
			$session = $this->app();
			$announced = [];
			$r = $this->manager($session)->buildIndexes(static function (int $gen) use (&$announced): void {
				$announced[] = $gen;
			});
			$this->assertSame([1], array_keys($r['failed']));
			$this->assertNotSame('', $r['failed'][1]);
			$this->assertSame([2], $r['built']);
			$this->assertSame([1, 2], $announced);
			$this->assertTrue((bool)$session->query('SELECT current_user = session_user')->fetchColumn(), 'the owner role is given back after a failure too');
		} finally {
			$su->exec('DROP SEQUENCE aps_search.chunk_vec_1_hnsw');
		}
	}

	public function testAGenerationThatHasItsIndexIsLeftAloneWithoutWaitingForWriters(): void {
		$this->readyGeneration(1);
		$this->candidates()->buildIndex(1);
		$oid = $this->indexOid();
		$writer = $this->app();
		$writer->beginTransaction();
		$writer->exec('DELETE FROM aps_search.chunk_vec_1');                 // a stray DROP INDEX CONCURRENTLY would wait for it
		$session = $this->app();
		$session->exec("SET lock_timeout = '1s'");
		$announced = [];
		$started = microtime(true);
		$r = $this->manager($session)->buildIndexes(static function (int $gen) use (&$announced): void {
			$announced[] = $gen;
		});
		$this->assertLessThan(0.5, microtime(true) - $started);
		$this->assertSame(['built' => [], 'failed' => []], $r);
		$this->assertSame([], $announced);
		$this->assertSame($oid, $this->indexOid(), 'not rebuilt');
	}

	public function testAReadyOrActiveGenerationThatLostItsIndexGetsItBackOnTheNextRefresh(): void {
		$this->readyGeneration(1);
		$this->manager()->buildIndexes(static fn (int $gen) => null);
		$idx = static fn ($t) => (int)$t->app()->query("SELECT count(*) FROM pg_indexes WHERE tablename = 'chunk_vec_1' AND indexdef ILIKE '%hnsw%'")->fetchColumn();
		$this->assertSame(1, $idx($this), 'built for a ready generation');
		self::connect('postgres', 'dev', self::$dbName)->exec('DROP INDEX aps_search.chunk_vec_1_hnsw');
		$this->manager()->buildIndexes(static fn (int $gen) => null);
		$this->assertSame(1, $idx($this));
	}

	public function testActivationNeedsTheVectorIndex(): void {
		$this->readyGeneration(1);
		$m = $this->manager();
		$this->refuses(fn () => $m->activate(1), 'no vector index');
		$this->assertSame(['1:ready'], $this->states(), 'searches would scan every vector');
		$this->candidates()->buildIndex(1);
		$m->activate(1);
		$this->assertSame(['1:active'], $this->states());
	}

	public function testShowListsGenerationsWithCoverageAndNeverTheSecrets(): void {
		$m = $this->manager();
		$m->set('bge-m3', 'http://ollama:11434', 'tok');
		$s = $m->show();
		$this->assertSame(1, $s['generations'][0]['gen']);
		$this->assertSame(['building', 'bge-m3:567m', 1024], [$s['generations'][0]['state'], $s['generations'][0]['model'], $s['generations'][0]['dim']]);
		$this->assertTrue($s['endpoint_configured']);
		$this->assertFalse($s['semantic_enabled']);
		$this->assertStringNotContainsString('tok', json_encode($s));
		$this->assertStringNotContainsString('ollama:11434', json_encode($s));
	}
}
