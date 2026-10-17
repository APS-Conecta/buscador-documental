<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Audit;

use InvalidArgumentException;
use OCA\BuscadorDocumental\Audit\AuditDetail;
use PHPUnit\Framework\TestCase;

/** The audit detail is a closed schema: counts, ids and reason codes only, never text. */
class AuditDetailTest extends TestCase {
	public function testCountsIdsAndCodesPass(): void {
		$d = AuditDetail::check(['rule_id' => 3, 'count' => 120, 'reason' => 'user_deleted', 'kind' => 'folder', 'mode' => 'opt_in']);
		$this->assertSame(['rule_id' => 3, 'count' => 120, 'reason' => 'user_deleted', 'kind' => 'folder', 'mode' => 'opt_in'], $d);
	}

	/** @dataProvider bad */
	public function testAnythingElseIsRefused(array $detail): void {
		$this->expectException(InvalidArgumentException::class);
		AuditDetail::check($detail);
	}

	public static function bad(): array {
		return [
			'unknown key' => [['filename' => 'informe.pdf']],
			'free text value' => [['reason' => 'el paciente Juan Pérez']],
			'long value' => [['reason' => str_repeat('a', 41)]],
			'upper case or spaces' => [['mode' => 'Opt In']],
			'array value' => [['count' => [1, 2]]],
			'float' => [['count' => 1.5]],
			'null' => [['reason' => null]],
		];
	}

	public function testBooleansAreAllowedAsFlags(): void {
		$this->assertSame(['paused' => true], AuditDetail::check(['paused' => true]));
	}
}
