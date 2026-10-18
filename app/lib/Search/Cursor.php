<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Search;

use InvalidArgumentException;

/**
 * The opaque paging token: snapshot id and position, bound to the user it was issued to and signed with the instance
 * secret. It holds no query text, no file id and no user name; a token for another user, a changed one or garbage reads as null.
 */
final class Cursor {
	private const MAX_LENGTH = 512;

	public function __construct(private string $secret) {
		if ($secret === '') {
			throw new InvalidArgumentException('A cursor needs a secret');
		}
	}

	public static function uidHash(string $uid): string {
		return hash('sha256', 'buscador-documental-cursor:' . $uid);
	}

	/** The signing key is derived from the instance secret, never the secret itself. */
	public static function forInstance(string $instanceSecret): self {
		return new self(hash_hmac('sha256', 'buscador-documental-cursor-v1', $instanceSecret));
	}

	/** @param ?int $back where the page before this one started (refills make pages uneven, so Previous cannot be computed) */
	public function make(string $qid, int $pos, string $uid, ?int $back = null): string {
		$payload = ['q' => $qid, 'p' => $pos, 'u' => self::uidHash($uid)];
		if ($back !== null) {
			$payload['b'] = $back;
		}
		$body = self::b64(json_encode($payload, JSON_THROW_ON_ERROR));
		return $body . '.' . $this->mac($body);
	}

	/** @return array{qid: string, pos: int, back: ?int}|null */
	public function read(string $token, string $uid): ?array {
		if ($token === '' || strlen($token) > self::MAX_LENGTH || substr_count($token, '.') !== 1) {
			return null;
		}
		[$body, $mac] = explode('.', $token);
		if ($body === '' || !hash_equals($this->mac($body), $mac)) {
			return null;
		}
		$data = json_decode((string)base64_decode(strtr($body, '-_', '+/'), true), true);
		if (!is_array($data) || !is_string($data['q'] ?? null) || !is_int($data['p'] ?? null) || $data['p'] < 0
			|| !hash_equals(self::uidHash($uid), (string)($data['u'] ?? ''))) {
			return null;
		}
		$back = $data['b'] ?? null;
		if ($back !== null && (!is_int($back) || $back < 0)) {
			return null;
		}
		return ['qid' => $data['q'], 'pos' => $data['p'], 'back' => $back];
	}

	private function mac(string $body): string {
		return self::b64(hash_hmac('sha256', $body, $this->secret, true));
	}

	private static function b64(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}
}
