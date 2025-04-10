<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'config#setUserConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setSensitiveUserConfig', 'url' => '/config/sensitive', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'config#setSensitiveAdminConfig', 'url' => '/admin-config/sensitive', 'verb' => 'PUT'],

		['name' => 'watsonxAPI#getModels', 'url' => '/models', 'verb' => 'GET'],
		['name' => 'watsonxAPI#getUserQuotaInfo', 'url' => '/quota-info', 'verb' => 'GET'],
		['name' => 'watsonxAPI#getAdminQuotaInfo', 'url' => '/admin-quota-info', 'verb' => 'GET'],
	],
];
