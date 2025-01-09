<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCP\App\IAppManager;
use OCP\Server;

require_once __DIR__ . '/../../../tests/bootstrap.php';

Server::get(IAppManager::class)->loadApps();
OC_Hook::clear();
