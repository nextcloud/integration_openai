<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\StreamingService;
use OCP\IAppConfig;
use OCP\Server;

require_once __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this script from the CLI.\n");
	exit(1);
}

if ($argc < 2) {
	fwrite(STDERR, "Usage: php tests/streaming_service_repro.php <base-url>\n");
	exit(1);
}

$baseUrl = rtrim($argv[1], '/');
$appConfig = Server::get(IAppConfig::class);
$appConfig->setValueString(Application::APP_ID, 'url', $baseUrl, lazy: false);
$appConfig->setValueString(Application::APP_ID, 'api_key', 'dummy-key', lazy: false);

$service = Server::get(StreamingService::class);
$start = microtime(true);

$generator = $service->streamRequest(
	null,
	'chat/completions',
	[
		'model' => 'dummy-model',
		'messages' => [['role' => 'user', 'content' => 'hello']],
		'stream' => true,
	],
	null,
	false,
);

$index = 0;
foreach ($generator as $partial) {
	$index++;
	printf("partial=%d at=%.6f data=%s\n", $index, microtime(true) - $start, $partial);
	flush();
}

$result = $generator->getReturn();
printf("done_at=%.6f usage=%s\n", microtime(true) - $start, json_encode($result));
