<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCA\BuscadorDocumental\Audit\Actor;
use OCA\BuscadorDocumental\Audit\AuditLog;
use OCA\BuscadorDocumental\Db\IndexDb;
use OCA\BuscadorDocumental\Scope\Settings;
use OCP\IAppConfig;
use Throwable;

/**
 * What `occ buscador:profile` does (ADR-0004, ADR-0014): set up a generation for a catalogue profile, activate a ready one, drop
 * an old one, switch the semantic modes on or off. Nothing here runs by itself; changing the profile is always an administrator's act.
 */
class ProfileManager {
	private const APP = 'buscador_documental';
	private const SAMPLE = 'Resumen estadístico mensual de controles de salud cardiovascular.';

	public function __construct(
		private IndexDb $db,
		private ProfileCatalog $catalog,
		private EmbedClient $embed,
		private IAppConfig $config,
		private Settings $settings,
		private AuditLog $audit,
		private CandidateQuery $candidates,
	) {
	}

	/**
	 * @param ?string $token null keeps the stored token, '' clears it
	 * @return array{gen: int, digest: string, dim: int, model: string, warnings: list<string>}
	 * @throws ProfileException
	 */
	public function set(string $profileId, string $url, ?string $token): array {
		$profile = $this->catalog->find($profileId) ?? throw new ProfileException("Unknown profile '$profileId'. Known: " . implode(', ', array_keys($this->catalog->all())));
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			throw new ProfileException('The address must be an http or https URL.');
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			throw new ProfileException('The address must not carry a user name or password: use the token option.');
		}
		if ($this->db->rows("SELECT 1 FROM pg_extension WHERE extname = 'vector'") === []) {
			throw new ProfileException('PostgreSQL has no vector extension (pgvector 0.8 or later). An administrator must run CREATE EXTENSION vector in this database.');
		}
		$previous = [$this->config->getValueString(self::APP, 'embed_url', ''), $this->config->getValueString(self::APP, 'embed_token', '')];
		$this->config->setValueString(self::APP, 'embed_url', rtrim($url, '/'));
		if ($token !== null) {
			$this->config->setValueString(self::APP, 'embed_token', $token, false, true);
		}
		try {
			$probe = $this->embed->inspect($profile['model'], $profile['max_tokens'], self::SAMPLE, 30000);
			if ($probe['dim'] !== $profile['dim']) {
				throw new ProfileException(sprintf('The model returned vectors of dimension %d but the catalogue says %d. The profile does not match this model.', $probe['dim'], $profile['dim']));
			}
			foreach ($this->db->rows("SELECT gen FROM aps_search.embedding_profile WHERE state <> 'retired' AND model = ? AND digest = ? AND dim = ? AND query_prefix = ? AND passage_prefix = ?",
				[$profile['model'], $probe['digest'], $probe['dim'], $profile['query_prefix'], $profile['passage_prefix']]) as $same) {
				throw new ProfileException("Generation {$same['gen']} already uses this model and digest.");
			}
			$gen = 1 + (int)$this->db->rows('SELECT coalesce(max(gen), 0) AS g FROM aps_search.embedding_profile')[0]['g'];
			$this->db->rows('SELECT aps_search.create_generation(?, ?, ?, ?, ?, ?, ?, ?, ?)', [
				$gen, $profile['id'], $profile['model'], $probe['digest'], $probe['dim'], $profile['quant'], $profile['query_prefix'], $profile['passage_prefix'], $profile['max_tokens'],
			]);
		} catch (ProbeFailed $e) {
			$this->restore($previous);
			throw new ProfileException(match ($e->reason) {
				'model_not_found' => "The endpoint does not have the model. Run: ollama pull {$profile['model']}",
				'bad_answer' => 'The endpoint answered, but not with a usable embedding.',
				'not_configured' => 'No usable endpoint address.',
				default => 'Could not reach the embedding endpoint (check the address, the token and that Nextcloud may connect to it).',
			});
		} catch (Throwable $e) {
			$this->restore($previous);
			throw $e;
		}
		$this->settings->set('vec_coverage_' . $gen, '0.0000');
		$this->audit->record(Actor::Occ, 'profile.set', null, ['gen' => $gen, 'profile' => $profile['id']]);
		$host = strtolower(trim((string)$parts['host'], '[]'));
		$loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
		$sameNetworkName = !str_contains($host, '.') && !str_contains($host, ':');       // a service name such as `ollama`
		$warnings = [];
		if (strtolower((string)$parts['scheme']) === 'http' && !$loopback && !$sameNetworkName) {
			$warnings[] = 'The address is plain http beyond this machine: document text and the token travel unencrypted. Use https behind a proxy that asks for authentication.';
		}
		return ['gen' => $gen, 'digest' => $probe['digest'], 'dim' => $probe['dim'], 'model' => $profile['model'], 'warnings' => $warnings];
	}

	/** @throws ProfileException */
	public function activate(int $gen): void {
		$state = $this->stateOf($gen);
		if ($state !== 'ready' && $state !== 'active') {
			throw new ProfileException("Generation $gen is not ready (it is $state). Wait until coverage reaches the threshold; see `occ buscador:profile show`.");
		}
		if (!$this->candidates->hasAnnIndex($gen)) {
			throw new ProfileException("Generation $gen has no vector index yet: run `occ buscador:profile refresh` first.");
		}
		$this->db->rows('SELECT aps_search.activate_generation(?)', [$gen]);
		$this->settings->set('vec_coverage', $this->settings->get('vec_coverage_' . $gen) ?? '0.0000');
		$this->audit->record(Actor::Occ, 'profile.activate', null, ['gen' => $gen]);
	}

	/**
	 * Builds the vector index of every ready or active generation that lacks one; $onBuild hears of each build before it starts.
	 * One generation's failure does not stop the others: its message is returned under its number.
	 *
	 * @param callable(int): void $onBuild
	 * @return array{built: list<int>, failed: array<int, string>}
	 */
	public function buildIndexes(callable $onBuild): array {
		$built = [];
		$failed = [];
		foreach ($this->db->rows("SELECT gen FROM aps_search.embedding_profile WHERE state IN ('ready', 'active') ORDER BY gen") as $row) {
			$gen = (int)$row['gen'];
			try {
				if ($this->candidates->hasAnnIndex($gen)) {
					continue;
				}
				$onBuild($gen);
				$this->candidates->buildIndex($gen);
				$built[] = $gen;
			} catch (Throwable $e) {
				$failed[$gen] = $e->getMessage();
			}
		}
		return ['built' => $built, 'failed' => $failed];
	}

	/** @throws ProfileException */
	public function drop(int $gen): void {
		$state = $this->stateOf($gen);
		if ($state === 'active') {
			throw new ProfileException("Generation $gen is the active generation. Activate another one first.");
		}
		$this->db->rows('SELECT aps_search.drop_generation(?)', [$gen]);
		$this->db->execute('DELETE FROM aps_search.settings WHERE key = ?', ['vec_coverage_' . $gen]);
		$this->audit->record(Actor::Occ, 'profile.drop', null, ['gen' => $gen]);
	}

	/** @throws ProfileException */
	public function semantic(bool $on): void {
		if ($this->db->rows("SELECT 1 FROM aps_search.embedding_profile WHERE state = 'active'") === []) {
			throw new ProfileException('There is no active profile. Set one up and activate it first.');
		}
		$this->settings->set('semantic_enabled', $on ? '1' : '0');
		$this->audit->record(Actor::Occ, 'semantic.set', null, ['paused' => !$on]);
	}

	/** @return array{generations: list<array<string, mixed>>, endpoint_configured: bool, semantic_enabled: bool, coverage: ?float, min_coverage: float} */
	public function show(): array {
		$gens = [];
		foreach ($this->db->rows('SELECT gen, profile_id, model, dim, state FROM aps_search.embedding_profile ORDER BY gen') as $g) {
			$gens[] = [
				'gen' => (int)$g['gen'], 'profile' => (string)$g['profile_id'], 'model' => (string)$g['model'], 'dim' => (int)$g['dim'], 'state' => (string)$g['state'],
				'coverage' => (float)($this->settings->get('vec_coverage_' . $g['gen']) ?? 0),
			];
		}
		$cov = $this->settings->get('vec_coverage');
		return [
			'generations' => $gens,
			'endpoint_configured' => $this->config->getValueString(self::APP, 'embed_url', '') !== '',
			'semantic_enabled' => $this->settings->get('semantic_enabled') === '1',
			'coverage' => $cov === null ? null : (float)$cov,
			'min_coverage' => (float)($this->settings->get('semantic_min_coverage') ?? VectorState::DEFAULT_MIN_COVERAGE),
		];
	}

	private function stateOf(int $gen): string {
		$rows = $this->db->rows('SELECT state FROM aps_search.embedding_profile WHERE gen = ?', [$gen]);
		if ($rows === []) {
			throw new ProfileException("Unknown generation $gen.");
		}
		return (string)$rows[0]['state'];
	}

	/** @param array{0: string, 1: string} $previous */
	private function restore(array $previous): void {
		if ($previous[0] === '') {
			$this->config->deleteKey(self::APP, 'embed_url');
		} else {
			$this->config->setValueString(self::APP, 'embed_url', $previous[0]);
		}
		if ($previous[1] === '') {
			$this->config->deleteKey(self::APP, 'embed_token');
		} else {
			$this->config->setValueString(self::APP, 'embed_token', $previous[1], false, true);
		}
	}
}
