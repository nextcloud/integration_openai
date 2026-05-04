<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

while (ob_get_level() > 0) {
	ob_end_flush();
}

for ($i = 1; $i <= 5; $i++) {
	echo 'data: ' . json_encode([
		'index' => $i,
		'time' => microtime(true),
		'choices' => [[
			'delta' => ['content' => 'chunk-' . $i],
		]],
	]) . "\n\n";
	flush();
	sleep(1);
}

echo "data: [DONE]\n\n";
flush();
