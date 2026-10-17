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

	/**
	 * Keeps taking batches for up to $seconds (always at least one), so a large backlog (an initial scan can queue
	 * hundreds of thousands of rows) drains at the pace the database allows instead of one batch a minute. It stops as
	 * soon as a batch is not full or makes no progress (everything held by a pause, or failing), so it cannot spin.
	 *
	 * @return array<string, int> outcome => count
	 */
	public function runFor(int $seconds, int $batch): array {
		$start = microtime(true);
		$total = [];
		do {
			$counts = $this->runBatch($batch);
			foreach ($counts as $outcome => $n) {
				$total[$outcome] = ($total[$outcome] ?? 0) + $n;
			}
			$taken = array_sum($counts);
			$progress = $taken - ($counts['held'] ?? 0) - ($counts['retry'] ?? 0);
		} while ($taken >= $batch && $progress > 0 && microtime(true) - $start < $seconds);
		return $total;
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
