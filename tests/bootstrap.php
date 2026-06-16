<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use OCP\App\IAppManager;
use OCP\Server;

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

require_once __DIR__ . '/../../../lib/base.php';
require_once __DIR__ . '/../../../tests/autoload.php';

if (!interface_exists(\OCP\TaskProcessing\ISynchronousOptionsAwareProvider::class)) {
	require_once __DIR__ . '/stubs/ocp_task_processing_i_provider.php';
	require_once __DIR__ . '/stubs/ocp_task_processing_isynchronous_provider.php';
	require_once __DIR__ . '/stubs/ocp_task_processing_synchronous_provider_options.php';
	require_once __DIR__ . '/stubs/ocp_task_processing_isynchronous_options_provider.php';
}

Server::get(IAppManager::class)->loadApp('integration_openai');
