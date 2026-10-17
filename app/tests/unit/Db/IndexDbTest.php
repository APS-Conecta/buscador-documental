<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Db;

use OCA\BuscadorDocumental\Db\IndexDb;
use PHPUnit\Framework\TestCase;

class IndexDbTest extends TestCase {
	public function testTextArrayQuotesAndEscapes(): void {
		$this->assertSame('{"u:a","g:b \"c\"","x\\\\y"}', IndexDb::textArray(['u:a', 'g:b "c"', 'x\\y']));
		$this->assertSame('{}', IndexDb::textArray([]));
	}
}
