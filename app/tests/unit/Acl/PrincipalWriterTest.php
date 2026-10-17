<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Acl;

use OCA\BuscadorDocumental\Acl\FileFacts;
use OCA\BuscadorDocumental\Acl\PrincipalWriter;
use PHPUnit\Framework\TestCase;

class PrincipalWriterTest extends TestCase {
	private function facts(?string $owner, array $users = [], array $groups = [], array $gf = []): FileFacts {
		return new FileFacts(7, 'a.pdf', 'application/pdf', 1, 'etag', $owner, $users, $groups, $gf);
	}

	public function testOwnerOnly(): void {
		$this->assertSame(['u:alice'], PrincipalWriter::principalsFor($this->facts('alice')));
	}

	public function testUserShareAddsUser(): void {
		$this->assertSame(['u:alice', 'u:bob'], PrincipalWriter::principalsFor($this->facts('alice', ['bob'])));
	}

	public function testGroupShareAndGroupfolderGroupsAreDeduplicated(): void {
		$p = PrincipalWriter::principalsFor($this->facts('alice', [], ['med', 'enf'], ['med', 'admin']));
		$this->assertSame(['g:admin', 'g:enf', 'g:med', 'u:alice'], $p);
	}

	public function testFileWithoutOwnerHasOnlyGrantedPrincipals(): void {
		$this->assertSame(['g:med'], PrincipalWriter::principalsFor($this->facts(null, [], ['med'])));
	}

	public function testFullyModelledFileDoesNotCarryTheRecheckMarker(): void {
		$this->assertNotContains('x:recheck', PrincipalWriter::principalsFor($this->facts('alice', ['bob'], ['g'])));
	}

	public function testUnmodelledAccessStoresTheRecheckMarker(): void {
		$f = new FileFacts(7, 'a.pdf', 'application/pdf', 1, 'etag', 'alice', [], [], [], true);
		$this->assertSame(['u:alice', 'x:recheck'], PrincipalWriter::principalsFor($f));
	}
}
