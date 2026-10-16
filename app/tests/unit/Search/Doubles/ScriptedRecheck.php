<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search\Doubles;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCA\BuscadorDocumental\Search\Recheck;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\Generator\Generator;
use Throwable;

require_once __DIR__ . '/EmitterStub.php';

/** Recheck whose node layer is scripted: file id => nodes (or a Throwable to raise), node => share. */
final class ScriptedRecheck extends Recheck {
	/** @var array<int, list<Node>|Throwable> */
	private array $nodes;
	/** @var array<int, IShare> spl_object_id(node) => share */
	private array $shares = [];

	/** @param array<int, list<Node>|Throwable> $nodes */
	public static function make(array $nodes, ExclusionChecker $checker): self {
		$self = new self((new Generator())->testDouble(IRootFolder::class, true), $checker);
		$self->nodes = $nodes;
		return $self;
	}

	public function behindShare(Node $node, IShare $share): void {
		$this->shares[spl_object_id($node)] = $share;
	}

	protected function nodesFor(string $uid, int $fileId): array {
		$n = $this->nodes[$fileId] ?? [];
		if ($n instanceof Throwable) {
			throw $n;
		}
		return $n;
	}

	protected function shareOf(Node $node): ?IShare {
		return $this->shares[spl_object_id($node)] ?? null;
	}
}
