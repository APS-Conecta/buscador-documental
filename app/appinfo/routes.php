<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'admin#overview', 'url' => '/admin/overview', 'verb' => 'GET'],
		['name' => 'admin#skipped', 'url' => '/admin/skipped', 'verb' => 'GET'],
		['name' => 'admin#pause', 'url' => '/admin/pause', 'verb' => 'POST'],
		['name' => 'admin#resume', 'url' => '/admin/resume', 'verb' => 'POST'],
		['name' => 'admin#acknowledgeNotice', 'url' => '/admin/notice/ack', 'verb' => 'POST'],
		['name' => 'admin#removeRule', 'url' => '/admin/scope/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'admin#retry', 'url' => '/admin/skipped/{id}/retry', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'file_text#folders', 'url' => '/folders', 'verb' => 'GET'],
		['name' => 'file_text#show', 'url' => '/file/{id}/text', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'file_text#reread', 'url' => '/file/{id}/reread', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'search#search', 'url' => '/search', 'verb' => 'POST'],
		['name' => 'search#modes', 'url' => '/search/modes', 'verb' => 'GET'],
		['name' => 'worker#meta', 'url' => '/worker/v1/files/{id}/meta', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'worker#content', 'url' => '/worker/v1/files/{id}/content', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
	],
];
