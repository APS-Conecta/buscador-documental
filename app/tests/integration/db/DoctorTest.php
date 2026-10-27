<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Ops\Doctor;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Search\EmbedClient;
use OCA\BuscadorDocumental\Search\ProbeFailed;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

class DoctorTest extends DbTestCase {
	/** @var array<string, string> */
	private array $app = ['worker_secret' => 'a-long-enough-worker-secret-0123456789ab', 'embed_url' => 'http://x'];
	private string $cron = 'cron';
	private string|ProbeFailed $digest = 'sha256:abc';
	private bool $tagExists = true;

	private function doctor(): Doctor {
		$db = new IndexDb($this->connection($this->app()));
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueString')->willReturnCallback(fn (string $a, string $k, string $d = '') => $this->app[$k] ?? $d);
		$sys = $this->createMock(IConfig::class);
		$sys->method('getAppValue')->willReturnCallback(fn (string $a, string $k, string $d = '') => $k === 'backgroundjobs_mode' ? $this->cron : $d);
		$embed = $this->createMock(EmbedClient::class);
		$embed->method('currentDigest')->willReturnCallback(function () {
			if ($this->digest instanceof ProbeFailed) {
				throw $this->digest;
			}
			return $this->digest;
		});
		$tags = $this->createMock(ISystemTagManager::class);
		$tags->method('getAllTags')->willReturnCallback(function (): array {
			if (!$this->tagExists) {
				return [];
			}
			$t = $this->createMock(ISystemTag::class);
			$t->method('getId')->willReturn('3');
			$t->method('getName')->willReturn(ExclusionChecker::TAG_NAME);
			return [$t];
		});
		$exclusion = new ExclusionChecker($this->createMock(IDBConnection::class), $tags, $this->createMock(ISystemTagObjectMapper::class));
		return new Doctor($db, new Settings($db), $cfg, $sys, $embed, $exclusion);
	}

	/** @return array<string, array{status: string, detail: string}> */
	private function checks(): array {
		$out = [];
		foreach ($this->doctor()->run() as $c) {
			$out[$c['id']] = ['status' => $c['status'], 'detail' => $c['detail']];
		}
		return $out;
	}

	private function statusOf(string $id): string {
		return $this->checks()[$id]['status'] ?? 'missing';
	}

	private function profile(string $state = 'active'): void {
		$a = $this->app();
		$a->exec("SELECT aps_search.create_generation(1, 'bge-m3', 'bge-m3:567m', 'sha256:abc', 3, 'f16', '', '', 512)");
		$a->exec('SELECT aps_search.mark_generation_ready(1)');
		if ($state === 'active') {
			$a->exec('SELECT aps_search.activate_generation(1)');
		}
	}

	public function testAFreshHealthySystemHasNoWarningsOrFailures(): void {
		$bad = array_filter($this->checks(), static fn (array $c): bool => $c['status'] !== 'ok');
		$this->assertSame([], $bad);
		$this->assertCount(0, array_filter($this->doctor()->run(), static fn (array $c): bool => !in_array($c['status'], ['ok', 'warn', 'fail'], true)));
	}

	public function testAMissingExclusionTagFailsWithARemedyThatWorksOnAnEnabledApp(): void {
		$this->assertSame('ok', $this->statusOf('exclusion_tag'));
		$this->tagExists = false;
		$checks = $this->checks();
		$this->assertSame('fail', $checks['exclusion_tag']['status'] ?? 'missing');
		$this->assertStringContainsString('occ tag:add rag-excluded public', $checks['exclusion_tag']['detail']);
		$this->assertStringNotContainsString('enable', $checks['exclusion_tag']['detail'], 'enabling an enabled app does nothing');
		$this->assertSame(1, Doctor::exitCode($this->doctor()->run()));
	}

	public function testAnUnboundedWorkerRoleIsAWarning(): void {
		$su = self::connect('postgres', 'dev', self::$dbName);
		$this->assertSame('ok', $this->statusOf('worker_role_limits'));
		try {
			$su->exec('ALTER ROLE bd_worker RESET temp_file_limit');
			$this->assertSame('warn', $this->statusOf('worker_role_limits'));
		} finally {
			$su->exec("ALTER ROLE bd_worker SET temp_file_limit = '2GB'");
		}
		$this->assertSame('ok', $this->statusOf('worker_role_limits'));
	}

	public function testEachBrokenPreconditionIsNamed(): void {
		$this->app['worker_secret'] = 'short';
		$this->assertSame('fail', $this->statusOf('worker_secret'));
		$this->app['worker_secret'] = '';
		$this->assertSame('fail', $this->statusOf('worker_secret'));
		$this->cron = 'ajax';
		$this->assertSame('warn', $this->statusOf('background_jobs'));
		$this->app()->exec("INSERT INTO aps_search.settings (key, value) VALUES ('paused', '1')");
		$this->assertSame('warn', $this->statusOf('paused'));
	}

	public function testWorkerLivenessMattersOnlyWhenThereIsWork(): void {
		$this->assertSame('ok', $this->statusOf('worker_alive'), 'no work, no worker: fine');
		$this->seedFile(1);
		$this->assertSame('fail', $this->statusOf('worker_alive'), 'work waiting and no worker ever seen');
		$this->worker()->prepare('SELECT * FROM aps_search.claim_job(?, 0, ?, 900)')->execute(['text', 'w1']);
		$this->assertSame('ok', $this->statusOf('worker_alive'));
		$this->app()->exec("UPDATE aps_search.worker_seen SET at = now() - interval '20 minutes'");
		$this->assertSame('fail', $this->statusOf('worker_alive'), 'seen long ago');
		$this->app()->exec("INSERT INTO aps_search.settings (key, value) VALUES ('paused', '1')");
		$this->assertSame('ok', $this->statusOf('worker_alive'), 'paused work does not need a worker');
	}

	public function testParkedEventsAndAnOldPermissionBacklogAreFlagged(): void {
		$this->assertSame('ok', $this->statusOf('outbox'));
		$this->app()->exec("INSERT INTO aps_search.outbox (file_id, kind, attempts) VALUES (1, 'file', 5)");
		$this->assertSame('warn', $this->statusOf('outbox'));
		$this->app()->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, acl_dirty, acl_dirty_since) VALUES (9, 'e', 'indexed', true, now() - interval '2 hours')");
		$this->assertSame('warn', $this->statusOf('permission_backlog'));
		$this->app()->exec("UPDATE aps_search.file_state SET acl_dirty_since = now() - interval '8 hours'");
		$this->assertSame('fail', $this->statusOf('permission_backlog'));
	}

	public function testReconciliationAgeCountsOnlyWhenThereAreScopeRules(): void {
		$this->assertSame('ok', $this->statusOf('reconciliation'), 'no rules, nothing to reconcile');
		$this->app()->exec("INSERT INTO aps_search.scope_rule (kind, file_id) VALUES ('folder', 5)");
		$this->assertSame('warn', $this->statusOf('reconciliation'), 'rules but never reconciled');
		$this->app()->exec("INSERT INTO aps_search.reconcile_run (finished_at, counts) VALUES (now() - interval '2 hours', '{}')");
		$this->assertSame('ok', $this->statusOf('reconciliation'));
		$this->app()->exec("UPDATE aps_search.reconcile_run SET finished_at = now() - interval '50 hours'");
		$this->assertSame('warn', $this->statusOf('reconciliation'));
	}

	public function testTheVectorChecksOnlyExistWithAProfile(): void {
		$r = $this->checks();
		$this->assertArrayNotHasKey('embedding_endpoint', $r);
		$this->profile();
		$this->assertSame('ok', $this->statusOf('embedding_endpoint'));
		$this->digest = 'sha256:other';
		$r = $this->checks();
		$this->assertSame('fail', $r['embedding_endpoint']['status']);
		$this->assertStringContainsString('changed', $r['embedding_endpoint']['detail']);
		$this->digest = new ProbeFailed('unreachable');
		$this->assertSame('warn', $this->statusOf('embedding_endpoint'), 'search falls back to keywords, so it is a warning');
		$this->digest = new ProbeFailed('model_not_found');
		$this->assertSame('fail', $this->statusOf('embedding_endpoint'));
		$this->app['embed_url'] = '';
		$this->assertSame('fail', $this->statusOf('embedding_endpoint'));
	}

	public function testAMissingVectorIndexAndFailedFilesAreWarnings(): void {
		$this->profile();
		$this->assertSame('warn', $this->statusOf('vector_index'), 'ready or active without an HNSW index');
		$this->app()->exec('SELECT aps_search.build_vec_index(1)');
		$this->assertSame('ok', $this->statusOf('vector_index'));
		$this->app()->exec("INSERT INTO aps_search.file_state (file_id, etag, text_state, vec_state) VALUES (1, 'e', 'indexed', 'failed')");
		$this->assertSame('warn', $this->statusOf('vector_failures'));
	}

	public function testNothingSecretOrTextCanAppearInTheReport(): void {
		$this->profile();
		$json = json_encode($this->doctor()->run());
		foreach (['a-long-enough-worker-secret', 'http://x', 'bge-m3', 'sha256'] as $leak) {
			$this->assertStringNotContainsString($leak, $json);
		}
	}

	public function testExitCodeFollowsTheWorstStatus(): void {
		$this->assertSame(0, Doctor::exitCode($this->doctor()->run()));
		$this->app['worker_secret'] = '';
		$this->assertSame(1, Doctor::exitCode($this->doctor()->run()));
		$this->assertSame(0, Doctor::exitCode([['id' => 'x', 'status' => 'warn', 'detail' => 'd']]), 'warnings do not fail a monitoring probe');
	}

	public function testAWindowTheDatabaseCannotInterpretIsFlaggedBecauseItWouldBeIgnored(): void {
		$this->assertArrayNotHasKey('night_window', $this->checks(), 'no window, no check');
		$a = $this->app();
		$a->exec("INSERT INTO aps_search.settings (key, value) VALUES ('work_window', '01:00-05:00'), ('work_tz', 'America/Santiago')");
		$this->assertSame('ok', $this->statusOf('night_window'));
		$a->exec("UPDATE aps_search.settings SET value = '+03:00' WHERE key = 'work_tz'");
		$r = $this->checks();
		$this->assertSame('fail', $r['night_window']['status']);
		$this->assertStringContainsString('ignored', $r['night_window']['detail']);
		$a->exec("UPDATE aps_search.settings SET value = 'America/Santiago' WHERE key = 'work_tz'");
		$a->exec("UPDATE aps_search.settings SET value = 'tonight' WHERE key = 'work_window'");
		$this->assertSame('fail', $this->statusOf('night_window'), 'an unusable window is silently ignored by the claim, so say so');
	}
}
