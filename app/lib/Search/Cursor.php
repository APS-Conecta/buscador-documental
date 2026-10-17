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

	public function make(string $qid, int $pos, string $uid): string {
		$body = self::b64(json_encode(['q' => $qid, 'p' => $pos, 'u' => self::uidHash($uid)], JSON_THROW_ON_ERROR));
		return $body . '.' . $this->mac($body);
	}

	/** @return array{qid: string, pos: int}|null */
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
		return ['qid' => $data['q'], 'pos' => $data['p']];
	}

	private function mac(string $body): string {
		return self::b64(hash_hmac('sha256', $body, $this->secret, true));
	}

	private static function b64(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}
}
