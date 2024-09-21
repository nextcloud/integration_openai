<?php

use OCP\App\IAppManager;
use OCP\Server;

require_once __DIR__ . '/../../../tests/bootstrap.php';

Server::get(IAppManager::class)->loadApps();
OC_Hook::clear();
