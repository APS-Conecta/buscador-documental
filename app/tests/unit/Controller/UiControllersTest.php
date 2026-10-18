<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Controller;

use OCA\BuscadorDocumental\Controller\AdminController;
use OCA\BuscadorDocumental\Controller\FileTextController;
use OCA\BuscadorDocumental\Controller\PageController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** The access rules of the UI endpoints live in attributes; this pins them so a refactor cannot loosen one silently. */
class UiControllersTest extends TestCase {
	private function has(string $class, string $method, string $attribute): bool {
		return (new ReflectionMethod($class, $method))->getAttributes($attribute) !== [];
	}

	public function testEveryAdminEndpointRequiresAnAdministratorAndCsrf(): void {
		foreach (['overview', 'skipped', 'pause', 'resume', 'acknowledgeNotice', 'removeRule', 'retry'] as $m) {
			$this->assertFalse($this->has(AdminController::class, $m, NoAdminRequired::class), "$m must stay admin-only");
			$this->assertFalse($this->has(AdminController::class, $m, NoCSRFRequired::class), "$m must keep CSRF protection");
		}
	}

	public function testThePageIsOpenToUsersAndNavigableByUrl(): void {
		$this->assertTrue($this->has(PageController::class, 'index', NoAdminRequired::class));
		$this->assertTrue($this->has(PageController::class, 'index', NoCSRFRequired::class));
	}

	public function testFileTextIsForUsersAndRereadIsRateLimitedWithCsrf(): void {
		$this->assertTrue($this->has(FileTextController::class, 'show', NoAdminRequired::class));
		$this->assertTrue($this->has(FileTextController::class, 'folders', NoAdminRequired::class));
		$this->assertFalse($this->has(FileTextController::class, 'folders', NoCSRFRequired::class));
		$this->assertTrue($this->has(FileTextController::class, 'reread', NoAdminRequired::class));
		$this->assertTrue($this->has(FileTextController::class, 'reread', UserRateLimit::class));
		$this->assertFalse($this->has(FileTextController::class, 'reread', NoCSRFRequired::class));
		$this->assertFalse($this->has(FileTextController::class, 'show', NoCSRFRequired::class));
	}
}
