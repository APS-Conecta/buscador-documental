<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Ops;

use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;
use OCA\BuscadorDocumental\Search\CandidateQuery;
use OCA\BuscadorDocumental\Search\EmbedClient;
use OCA\BuscadorDocumental\Search\ProbeFailed;
use OCP\Encryption\IManager;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * `occ buscador:doctor`: is the system healthy, and if not, what exactly. Each check is ok, warn or fail with a code and a short
 * detail made of words and numbers; no secret, address, model name, file name or text ever appears. Exit code 1 only on a fail.
 */
class Doctor {
	private const APP = 'buscador_documental';
	public const SCHEMA = 6;

	public function __construct(
		private IndexDb $db,
		private Settings $settings,
		private IAppConfig $appConfig,
		private IConfig $config,
		private EmbedClient $embed,
		private CandidateQuery $candidates,
		private IManager $encryption,
	) {
	}

	/** @param list<array{id: string, status: string, detail: string}> $checks */
	public static function exitCode(array $checks): int {
		foreach ($checks as $c) {
			if ($c['status'] === 'fail') {
				return 1;
			}
		}
		return 0;
	}

	/** @return list<array{id: string, status: string, detail: string}> */
	public function run(): array {
		$c = [];
		$ver = (int)$this->db->rows('SELECT schema_ver FROM aps_search.schema_meta')[0]['schema_ver'];
		$c[] = $this->check('schema', $ver === self::SCHEMA ? 'ok' : 'fail', $ver === self::SCHEMA ? "version $ver" : "version $ver, expected " . self::SCHEMA . ': enable the app again to migrate');

		$roles = (int)$this->db->rows("SELECT count(*) AS n FROM pg_roles WHERE rolname IN ('bd_owner', 'bd_worker')")[0]['n'];
		$c[] = $this->check('database_roles', $roles === 2 ? 'ok' : 'fail', $roles === 2 ? 'bd_owner and bd_worker exist' : 'a database role is missing');
		$lim = $this->db->rows("SELECT r.rolconnlimit, coalesce(s.setconfig, '{}'::text[]) AS cfg FROM pg_roles r LEFT JOIN pg_db_role_setting s ON s.setrole = r.oid AND s.setdatabase = 0 WHERE r.rolname = 'bd_worker'");
		$cfg = $lim === [] ? '' : (string)$lim[0]['cfg'];
		$bounded = $lim !== [] && (int)$lim[0]['rolconnlimit'] > 0 && str_contains($cfg, 'statement_timeout=') && str_contains($cfg, 'temp_file_limit=');
		$c[] = $this->check('worker_role_limits', $bounded ? 'ok' : 'warn', $bounded ? 'the worker role has a connection limit, a statement timeout and a temp file limit' : 'the worker role is not bounded: an administrator should set CONNECTION LIMIT, statement_timeout and temp_file_limit on bd_worker (install guide)');
		$hasUnaccent = $this->db->rows("SELECT 1 FROM pg_extension WHERE extname = 'unaccent'") !== [];
		$c[] = $this->check('extension_unaccent', $hasUnaccent ? 'ok' : 'fail', $hasUnaccent ? 'installed' : 'missing: CREATE EXTENSION unaccent');

		$secretLen = strlen($this->appConfig->getValueString(self::APP, 'worker_secret', ''));
		$c[] = $this->check('worker_secret', $secretLen >= 32 ? 'ok' : 'fail', $secretLen >= 32 ? 'configured' : 'not configured or shorter than 32 characters');
		$sse = $this->encryption->isEnabled();
		$c[] = $this->check('server_encryption', $sse ? 'warn' : 'ok', $sse ? 'server-side encryption is on: the files it encrypts are skipped, so their content is not searchable' : 'off');

		$paused = $this->settings->isPaused();
		$c[] = $this->check('paused', $paused ? 'warn' : 'ok', $paused ? 'reading is paused: occ buscador:resume' : 'running');
		$c[] = $this->workerAlive($paused);

		$mode = $this->config->getAppValue('core', 'backgroundjobs_mode', 'ajax');
		$c[] = $this->check('background_jobs', $mode === 'cron' ? 'ok' : 'warn', $mode === 'cron' ? 'cron' : "mode is $mode: use cron, the buscador's jobs need their time budget");

		$o = $this->db->rows("SELECT count(*) FILTER (WHERE attempts >= 5 AND processed_at IS NULL) AS parked,
			count(*) FILTER (WHERE processed_at IS NULL AND attempts < 5 AND created_at < now() - interval '1 hour') AS old FROM aps_search.outbox")[0];
		$c[] = $this->check('outbox', ((int)$o['parked'] + (int)$o['old']) === 0 ? 'ok' : 'warn',
			((int)$o['parked'] + (int)$o['old']) === 0 ? 'no stuck events' : sprintf('%d parked after repeated failures, %d older than an hour', $o['parked'], $o['old']));

		$age = $this->db->rows('SELECT extract(epoch FROM now() - min(acl_dirty_since)) AS s FROM aps_search.file_state WHERE acl_dirty')[0]['s'];
		$sec = $age === null ? 0 : (int)$age;
		$c[] = $this->check('permission_backlog', $sec > 6 * 3600 ? 'fail' : ($sec > 3600 ? 'warn' : 'ok'),
			$age === null ? 'none' : "oldest pending change is $sec seconds old");

		$rules = (int)$this->db->rows('SELECT count(*) AS n FROM aps_search.scope_rule')[0]['n'];
		if ($rules === 0) {
			$c[] = $this->check('reconciliation', 'ok', 'no scope rules, nothing to reconcile');
		} else {
			$last = $this->db->rows('SELECT extract(epoch FROM now() - max(finished_at)) AS s FROM aps_search.reconcile_run WHERE finished_at IS NOT NULL')[0]['s'];
			$c[] = $this->check('reconciliation', $last === null || (int)$last > 36 * 3600 ? 'warn' : 'ok',
				$last === null ? 'never finished' : sprintf('last finished %d hours ago', intdiv((int)$last, 3600)));
		}

		$window = $this->settings->get('work_window');
		if ($window !== null && $window !== '') {
			$tz = $this->settings->get('work_tz') ?: 'UTC';
			$usable = preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]-([01][0-9]|2[0-3]):[0-5][0-9]$/D', $window) === 1
				&& $this->db->rows('SELECT 1 FROM pg_timezone_names WHERE name = ?', [$tz]) !== [];
			$c[] = $this->check('night_window', $usable ? 'ok' : 'fail', $usable ? 'set and understood by the database' : 'the window or its time zone is not understood by the database, so it is ignored and heavy work runs at any hour: set it again with occ buscador:window set');
		}

		return array_merge($c, $this->vectorChecks());
	}

	/** @return array{id: string, status: string, detail: string} */
	private function workerAlive(bool $paused): array {
		$work = (int)$this->db->rows('SELECT count(*) AS n FROM aps_search.job')[0]['n'];
		$seen = $this->db->rows('SELECT extract(epoch FROM now() - max(at)) AS s FROM aps_search.worker_seen')[0]['s'];
		if ($work === 0 || $paused) {
			return $this->check('worker_alive', 'ok', $seen === null ? 'no worker has connected yet (nothing to do)' : sprintf('last seen %d seconds ago', (int)$seen));
		}
		if ($seen === null) {
			return $this->check('worker_alive', 'fail', "$work jobs wait and no worker has ever connected");
		}
		return $this->check('worker_alive', (int)$seen > 600 ? 'fail' : 'ok', sprintf('%d jobs wait; a worker was last seen %d seconds ago', $work, (int)$seen));
	}

	/** @return list<array{id: string, status: string, detail: string}> */
	private function vectorChecks(): array {
		$gens = $this->db->rows("SELECT gen, model, digest, state FROM aps_search.embedding_profile WHERE state IN ('building', 'ready', 'active') ORDER BY gen");
		if ($gens === []) {
			return [];
		}
		$out = [];
		$live = null;
		foreach ($gens as $g) {
			if ($g['state'] === 'active') {
				$live = $g;
			}
		}
		$g = $live ?? $gens[0];
		if ($this->appConfig->getValueString(self::APP, 'embed_url', '') === '') {
			$out[] = $this->check('embedding_endpoint', 'fail', 'no endpoint address is configured');
		} else {
			try {
				$now = $this->embed->currentDigest((string)$g['model'], 5000);
				$out[] = $now === (string)$g['digest']
					? $this->check('embedding_endpoint', 'ok', 'reachable, the model is the one the profile recorded')
					: $this->check('embedding_endpoint', 'fail', 'the model behind the tag changed since the profile was set: vectors would mix; set the profile up again');
			} catch (ProbeFailed $e) {
				$out[] = match ($e->reason) {
					'model_not_found' => $this->check('embedding_endpoint', 'fail', 'the endpoint no longer has the model'),
					default => $this->check('embedding_endpoint', 'warn', 'the endpoint did not answer: meaning-based search falls back to words'),
				};
			}
		}
		$missing = 0;
		foreach ($gens as $row) {
			if ($row['state'] !== 'building') {
				$missing += !$this->candidates->hasAnnIndex((int)$row['gen']) ? 1 : 0;
			}
		}
		$out[] = $this->check('vector_index', $missing === 0 ? 'ok' : 'warn', $missing === 0 ? 'present' : "$missing generation(s) without the vector index: searches would scan everything; occ buscador:profile refresh builds it");
		$failed = (int)$this->db->rows("SELECT count(*) AS n FROM aps_search.file_state WHERE vec_state = 'failed'")[0]['n'];
		$out[] = $this->check('vector_failures', $failed === 0 ? 'ok' : 'warn', $failed === 0 ? 'none' : "$failed file(s) whose vectors were given up on: occ buscador:profile refresh --retry-failed");
		return $out;
	}

	/** @return array{id: string, status: string, detail: string} */
	private function check(string $id, string $status, string $detail): array {
		return ['id' => $id, 'status' => $status, 'detail' => $detail];
	}
}
