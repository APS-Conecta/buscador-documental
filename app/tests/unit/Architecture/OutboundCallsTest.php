<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Architecture;

use PHPUnit\Framework\TestCase;

/** A-OPS-4, second line (the first is the network): the only code in the app that talks to another host is EmbedClient. */
class OutboundCallsTest extends TestCase {
	private const ALLOWED = ['Search/EmbedClient.php'];
	private const PATTERNS = ['IClientService', 'IClient\b', 'curl_init', 'curl_exec', 'fsockopen', 'stream_socket_client', 'socket_connect', 'GuzzleHttp', 'file_get_contents\(\s*[\'"]https?:', 'fopen\(\s*[\'"]https?:', 'simplexml_load_file\(\s*[\'"]https?:', 'SoapClient'];

	/** @return list<string> */
	private function phpFiles(): array {
		$out = [];
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../lib', \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if ($f->isFile() && $f->getExtension() === 'php') {
				$out[] = $f->getPathname();
			}
		}
		sort($out);
		return $out;
	}

	public function testOnlyTheEmbeddingClientMakesOutboundCalls(): void {
		$hits = [];
		foreach ($this->phpFiles() as $path) {
			$rel = substr($path, strpos($path, '/lib/') + 5);
			$code = (string)file_get_contents($path);
			foreach (self::PATTERNS as $p) {
				if (preg_match('/' . $p . '/', $code) === 1 && !in_array($rel, self::ALLOWED, true)) {
					$hits[] = "$rel matches $p";
				}
			}
		}
		$this->assertSame([], $hits);
	}

	public function testTheScannerSeesTheAllowedFileSoItIsNotBlind(): void {
		$code = (string)file_get_contents(__DIR__ . '/../../../lib/Search/EmbedClient.php');
		$this->assertSame(1, preg_match('/IClientService/', $code), 'the pattern list still catches the one legitimate user');
		$this->assertGreaterThan(40, count($this->phpFiles()));
	}

	public function testTheEmbeddingClientSendsOnlyToTheAdministratorsAddress(): void {
		$code = (string)file_get_contents(__DIR__ . '/../../../lib/Search/EmbedClient.php');
		$this->assertSame(0, preg_match('/https?:\/\/[a-z0-9]/i', preg_replace('/\/\/.*$|\/\*.*?\*\//ms', '', $code) ?? ''), 'no URL literal in the client: the address comes from the setting');
	}
}
