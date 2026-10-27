<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Tests\unit\Search\Doubles;

use OCA\BuscadorDocumental\Acl\ExclusionChecker;
use OCP\IDBConnection;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\Generator\Generator;

/** ExclusionChecker over a fake filecache (id => parent, <= 0 is a storage root) and fake tags. */
final class TreeChecker extends ExclusionChecker {
	/**
	 * @param array<int, int> $tree
	 * @param list<array{0: int, 1: string}> $tags [id, name]
	 * @param array<string, list<string>> $tagged object id => tag ids
	 */
	public static function make(array $tree, array $tags, array $tagged, ?\Throwable $mapperFails = null): self {
		$gen = new Generator();
		$manager = $gen->testDouble(ISystemTagManager::class, true);
		$objects = [];
		foreach ($tags as [$id, $name]) {
			$t = $gen->testDouble(ISystemTag::class, true);
			$t->method('getId')->willReturn((string)$id);
			$t->method('getName')->willReturn($name);
			$objects[] = $t;
		}
		$manager->method('getAllTags')->willReturn($objects);
		return self::over($tree, $manager, $tagged, $mapperFails);
	}

	/**
	 * The same over a tag manager the caller scripts (for example one whose tags change while the test runs).
	 *
	 * @param array<int, int> $tree
	 * @param array<string, list<string>> $tagged object id => tag ids
	 */
	public static function over(array $tree, ISystemTagManager $manager, array $tagged = [], ?\Throwable $mapperFails = null): self {
		$gen = new Generator();
		$mapper = $gen->testDouble(ISystemTagObjectMapper::class, true);
		if ($mapperFails !== null) {
			$mapper->method('getTagIdsForObjects')->willThrowException($mapperFails);
		} else {
			$mapper->method('getTagIdsForObjects')->willReturn($tagged);
		}
		$self = new self($gen->testDouble(IDBConnection::class, true), $manager, $mapper);
		$self->tree = $tree;
		return $self;
	}

	/** @var array<int, int> */
	private array $tree = [];

	protected function parentOf(int $fileId): ?int {
		return $this->tree[$fileId] ?? null;
	}
}
