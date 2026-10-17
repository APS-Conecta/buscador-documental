<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'search#search', 'url' => '/search', 'verb' => 'POST'],
		['name' => 'search#modes', 'url' => '/search/modes', 'verb' => 'GET'],
		['name' => 'worker#meta', 'url' => '/worker/v1/files/{id}/meta', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'worker#content', 'url' => '/worker/v1/files/{id}/content', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
	],
];
