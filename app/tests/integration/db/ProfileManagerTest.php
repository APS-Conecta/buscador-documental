<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\integration\db;

use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Audit\Pseudonyms;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Search\EmbedClient;
use OCA\BuscadorDocumental\Search\ProbeFailed;
use OCA\BuscadorDocumental\Search\ProfileCatalog;
use OCA\BuscadorDocumental\Search\ProfileException;
use OCA\BuscadorDocumental\Search\ProfileManager;
use OCP\IAppConfig;

/** `occ buscador:profile`: setting up, activating and dropping embedding generations. */
class ProfileManagerTest extends DbTestCase {
	/** @var array<string, string> */
	private array $config = [];
	private array|ProbeFailed $probe = ['digest' => 'sha256:aaa', 'dim' => 1024];

	private function manager(): ProfileManager {
		$db = new IndexDb($this->connection($this->app()));
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
		return new ProfileManager($db, ProfileCatalog::shipped(), $embed, $app, new Settings($db), new AuditLog($db, new Pseudonyms($db)));
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
		$this->assertSame(['gen' => 1, 'digest' => 'sha256:aaa', 'dim' => 1024, 'model' => 'bge-m3:567m'], $r);
		$this->assertSame(['1:building'], $this->states());
		$this->assertSame('http://ollama:11434', $this->config['embed_url']);
		$this->assertSame(['s3cr3t', '1'], [$this->config['embed_token'], $this->config['embed_token.sensitive']], 'the token is stored as a sensitive value');
		$this->assertSame('1024', (string)$this->app()->query('SELECT dim FROM aps_search.embedding_profile WHERE gen = 1')->fetchColumn());
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
			$m = new ProfileManager($db, ProfileCatalog::shipped(), $this->createMock(EmbedClient::class), $this->createMock(IAppConfig::class), new Settings($db), new AuditLog($db, new Pseudonyms($db)));
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
		$m->activate(1);
		$this->assertSame(['1:active'], $this->states());
		$this->assertSame('0.9700', $this->app()->query("SELECT value FROM aps_search.settings WHERE key = 'vec_coverage'")->fetchColumn());
		$this->assertSame('profile.activate', $this->app()->query("SELECT action FROM aps_search.audit_log ORDER BY id DESC LIMIT 1")->fetchColumn());
	}

	public function testDropRefusesTheActiveGenerationAndForgetsTheRest(): void {
		$m = $this->manager();
		$m->set('bge-m3', 'http://ollama:11434', null);
		$this->app()->exec('SELECT aps_search.mark_generation_ready(1)');
		$m->activate(1);
		$this->probe = ['digest' => 'sha256:two', 'dim' => 1024];
		$m->set('bge-m3', 'http://ollama:11434', null);
		$this->refuses(fn () => $m->drop(1), 'active');
		$m->drop(2);
		$this->assertSame(['1:active'], $this->states());
		$this->assertNull((new Settings(new IndexDb($this->connection($this->app()))))->get('vec_coverage_2'));
	}

	public function testSemanticSwitchNeedsAnActiveProfile(): void {
		$m = $this->manager();
		$this->refuses(fn () => $m->semantic(true), 'active profile');
		$m->set('bge-m3', 'http://ollama:11434', null);
		$this->app()->exec('SELECT aps_search.mark_generation_ready(1)');
		$m->activate(1);
		$m->semantic(true);
		$this->assertSame('1', $this->app()->query("SELECT value FROM aps_search.settings WHERE key = 'semantic_enabled'")->fetchColumn());
		$m->semantic(false);
		$this->assertSame('0', $this->app()->query("SELECT value FROM aps_search.settings WHERE key = 'semantic_enabled'")->fetchColumn());
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
