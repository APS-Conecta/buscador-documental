<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Index;

use OCA\BuscadorDocumental\Db\IndexDb;
use Psr\Log\LoggerInterface;
use Throwable;

/** Takes a batch of outbox rows, has the processor evaluate each, and settles the rows. */
class OutboxRunner {
	public function __construct(private IndexDb $db, private Outbox $outbox, private Processor $processor, private LoggerInterface $logger) {
	}

	/** @return array<string, int> outcome => count */
	public function runBatch(int $limit): array {
		$counts = [];
		$done = [];
		$held = [];
		foreach ($this->outbox->claim($limit) as $row) {
			try {
				$outcome = $this->processor->process($row['file_id'], $row['kind']);
			} catch (Throwable $e) {
				$this->logger->warning('buscador_documental: outbox row failed (' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
				$outcome = 'retry';
			}
			$counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
			if ($outcome === 'held') {
				$held[] = $row['id'];
			} elseif ($outcome !== 'retry') {
				$done[] = $row['id'];
			}
		}
		$this->outbox->done($done);
		$this->outbox->release($held);
		return $counts;
	}
}
