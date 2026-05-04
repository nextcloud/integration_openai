<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCP\Http\Client\IClientService;
use OCP\Server;

require_once __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Run this script from the CLI.\n");
	exit(1);
}

if ($argc < 2) {
	fwrite(STDERR, "Usage: php tests/stream_i_client_repro.php <url> [read-bytes]\n");
	exit(1);
}

$url = $argv[1];
$readBytes = isset($argv[2]) ? max(1, (int)$argv[2]) : 1024;

$client = Server::get(IClientService::class)->newClient();
$start = microtime(true);

$response = $client->get($url, [
	'stream' => true,
	'timeout' => 30,
	'headers' => [
		'Accept' => 'text/event-stream',
	],
]);

$body = $response->getBody();

printf("response_at=%.6f status=%d content_type=%s body_type=%s\n",
	microtime(true) - $start,
	$response->getStatusCode(),
	$response->getHeader('Content-Type'),
	gettype($body),
);

if (!is_resource($body)) {
	fwrite(STDERR, "Body is not a resource.\n");
	exit(2);
}

$chunkIndex = 0;
while (!feof($body)) {
	$chunk = fread($body, $readBytes);
	if ($chunk === false) {
		fwrite(STDERR, "fread failed\n");
		exit(3);
	}
	if ($chunk === '') {
		continue;
	}

	$chunkIndex++;
	$preview = str_replace(["\r", "\n"], ['\\r', '\\n'], substr($chunk, 0, 120));
	printf("chunk=%d at=%.6f len=%d data=%s\n", $chunkIndex, microtime(true) - $start, strlen($chunk), $preview);
	flush();
}

printf("done_at=%.6f\n", microtime(true) - $start);
