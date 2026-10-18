<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Embeds a search query through the admin-configured Ollama endpoint (ADR-0014). Any problem yields null and the caller
 * falls back to keyword search: nothing here may fail a search. Logs say what kind of problem, never the endpoint address,
 * the token, the model name or the query text.
 */
class EmbedClient {
	private const APP = 'buscador_documental';
	private const DIGEST_TTL = 60;
	private ICache $cache;

	public function __construct(
		private IClientService $http,
		private IAppConfig $config,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createLocal(self::APP . '-embed');
	}

	/**
	 * @param array{gen: int, model: string, digest: string, dim: int, query_prefix: string, max_tokens: int} $profile
	 * @return list<float>|null a unit vector of the profile's dimension
	 */
	public function embedQuery(string $text, array $profile, int $timeoutMs): ?array {
		$base = $this->endpoint();
		if ($base === null) {
			$this->logger->warning('buscador_documental: embedding endpoint not configured or not usable');
			return null;
		}
		try {
			$options = $this->options($timeoutMs);
			if (!$this->digestMatches($base, $profile, $options)) {
				$this->logger->warning('buscador_documental: the embedding model changed since the profile was set');
				return null;
			}
			$body = json_encode([
				'model' => $profile['model'],
				'input' => [$profile['query_prefix'] . $text],
				'truncate' => false,
				'keep_alive' => -1,
				'options' => ['num_ctx' => $profile['max_tokens']],
			], JSON_THROW_ON_ERROR);
			$response = $this->http->newClient()->post($base . '/api/embed', $options + ['body' => $body]);
			if ($response->getStatusCode() !== 200) {
				$this->logger->warning('buscador_documental: embedding endpoint refused the query');
				return null;
			}
			$data = json_decode((string)$response->getBody(), true);
			return $this->unit($data['embeddings'][0] ?? null, $profile['dim']);
		} catch (Throwable $e) {
			$this->logger->warning('buscador_documental: embedding query failed (' . get_class($e) . ')');
			return null;
		}
	}

	/**
	 * What the administrator's setup command needs: the digest the endpoint reports for the model and the real vector size.
	 *
	 * @return array{digest: string, dim: int}
	 * @throws ProbeFailed with a reason code, never the address or the token
	 */
	public function inspect(string $model, int $maxTokens, string $sample, int $timeoutMs): array {
		$base = $this->endpoint();
		if ($base === null) {
			throw new ProbeFailed('not_configured');
		}
		$options = $this->options($timeoutMs);
		try {
			$tags = $this->http->newClient()->get($base . '/api/tags', $options);
			$data = $tags->getStatusCode() === 200 ? json_decode((string)$tags->getBody(), true) : null;
		} catch (Throwable) {
			throw new ProbeFailed('unreachable');
		}
		$digest = null;
		foreach (is_array($data) ? ($data['models'] ?? []) : [] as $m) {
			if (is_array($m) && ($m['name'] ?? null) === $model) {
				$digest = (string)($m['digest'] ?? '');
			}
		}
		if ($digest === null || $digest === '') {
			throw new ProbeFailed($data === null ? 'unreachable' : 'model_not_found');
		}
		try {
			$body = json_encode(['model' => $model, 'input' => [$sample], 'truncate' => false, 'keep_alive' => -1, 'options' => ['num_ctx' => $maxTokens]], JSON_THROW_ON_ERROR);
			$r = $this->http->newClient()->post($base . '/api/embed', $options + ['body' => $body]);
			$vec = $r->getStatusCode() === 200 ? (json_decode((string)$r->getBody(), true)['embeddings'][0] ?? null) : null;
		} catch (Throwable) {
			throw new ProbeFailed('unreachable');
		}
		$unit = is_array($vec) ? $this->unit($vec, count($vec)) : null;
		if ($unit === null) {
			throw new ProbeFailed('bad_answer');
		}
		return ['digest' => $digest, 'dim' => count($unit)];
	}

	private function endpoint(): ?string {
		$url = trim($this->config->getValueString(self::APP, 'embed_url', ''));
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
			|| isset($parts['user']) || isset($parts['pass'])) {
			return null;
		}
		return rtrim($url, '/');
	}

	/** @return array<string, mixed> */
	private function options(int $timeoutMs): array {
		$headers = ['Content-Type' => 'application/json'];
		$token = $this->config->getValueString(self::APP, 'embed_token', '');
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return [
			'headers' => $headers,
			'timeout' => max(0.2, $timeoutMs / 1000),
			'allow_redirects' => false,                                  // a redirect could carry the query text elsewhere
			'nextcloud' => ['allow_local_address' => true],              // only for the host the administrator configured
		];
	}

	/** @param array{model: string, digest: string} $profile @param array<string, mixed> $options */
	private function digestMatches(string $base, array $profile, array $options): bool {
		$key = 'digest:' . $profile['model'];
		$known = $this->cache->get($key);
		if ($known === null) {
			$response = $this->http->newClient()->get($base . '/api/tags', $options);
			$data = $response->getStatusCode() === 200 ? json_decode((string)$response->getBody(), true) : null;
			$known = '';
			foreach (is_array($data) ? ($data['models'] ?? []) : [] as $m) {
				if (is_array($m) && ($m['name'] ?? null) === $profile['model']) {
					$known = (string)($m['digest'] ?? '');
				}
			}
			$this->cache->set($key, $known, self::DIGEST_TTL);
		}
		return $known !== '' && $known === $profile['digest'];
	}

	/** @return list<float>|null */
	private function unit(mixed $v, int $dim): ?array {
		if (!is_array($v) || count($v) !== $dim) {
			return null;
		}
		$sum = 0.0;
		foreach ($v as $x) {
			if (!(is_int($x) || is_float($x)) || !is_finite((float)$x)) {
				return null;
			}
			$sum += $x * $x;
		}
		if ($sum < 1e-18) {
			return null;
		}
		$n = sqrt($sum);
		return array_map(static fn ($x): float => $x / $n, array_values($v));
	}
}
