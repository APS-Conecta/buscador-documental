<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\BuscadorDocumental\Worker;

use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/** Streams a file resource, whole (200) or one byte range (206), without loading it into memory. */
class RangeStreamResponse extends Response implements ICallbackResponse {
	/** @param resource $stream */
	public function __construct(private $stream, private int $start, private int $length, int $status, private array $own) {
		parent::__construct($status, $own);
	}

	/** The headers this response was built with (Response::getHeaders() also pulls in server-wide ones). */
	public function ownHeaders(): array {
		return $this->own;
	}

	public function callback(IOutput $output): void {
		if ($this->start > 0) {
			fseek($this->stream, $this->start);
		}
		$left = $this->length;
		while ($left > 0 && !feof($this->stream)) {
			$chunk = fread($this->stream, min(1048576, $left));
			if ($chunk === false || $chunk === '') {
				break;
			}
			echo $chunk;
			$left -= strlen($chunk);
		}
		fclose($this->stream);
	}
}
