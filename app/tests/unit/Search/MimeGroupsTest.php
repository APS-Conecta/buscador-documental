<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search;

use OCA\BuscadorDocumental\Search\MimeGroups;
use PHPUnit\Framework\TestCase;

class MimeGroupsTest extends TestCase {
	public function testEveryIndexedFormatBelongsToExactlyOneGroup(): void {
		$seen = [];
		foreach (MimeGroups::GROUPS as $group => $mimes) {
			foreach ($mimes as $m) {
				$this->assertArrayNotHasKey($m, $seen, "$m is in two groups");
				$seen[$m] = $group;
			}
		}
		foreach (['application/pdf' => 'pdf', 'image/png' => 'imagen', 'image/jpeg' => 'imagen', 'image/tiff' => 'imagen', 'text/plain' => 'texto', 'text/markdown' => 'texto',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
			'application/vnd.oasis.opendocument.text' => 'word',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'hoja',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'presentacion'] as $mime => $group) {
			$this->assertSame($group, $seen[$mime] ?? null, $mime);
		}
	}

	public function testMimesForKnownGroupsAreMergedAndUnknownGroupsAreRefused(): void {
		$this->assertSame(['application/pdf', 'image/jpeg', 'image/png', 'image/tiff'], MimeGroups::mimes(['pdf', 'imagen']));
		$this->assertSame(['application/pdf'], MimeGroups::mimes(['pdf', 'pdf']));
		$this->assertSame([], MimeGroups::mimes([]));
		$this->expectException(\InvalidArgumentException::class);
		MimeGroups::mimes(['pdf', "x'; DROP TABLE y; --"]);
	}

	public function testTheGroupNamesAreTheSpanishFilterValues(): void {
		$this->assertSame(['pdf', 'word', 'hoja', 'presentacion', 'texto', 'imagen'], array_keys(MimeGroups::GROUPS));
	}
}
