<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		['name' => 'config#setUserConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		['name' => 'service#index', 'url' => '/services', 'verb' => 'GET'],
		['name' => 'service#create', 'url' => '/services', 'verb' => 'POST'],
		['name' => 'service#update', 'url' => '/services/{id}', 'verb' => 'PUT'],
		['name' => 'service#updateSensitive', 'url' => '/services/{id}/sensitive', 'verb' => 'PUT'],
		['name' => 'service#destroy', 'url' => '/services/{id}', 'verb' => 'DELETE'],
		['name' => 'service#models', 'url' => '/services/{id}/models', 'verb' => 'GET'],
		['name' => 'service#autoDetectModalities', 'url' => '/services/{id}/auto-detect-modalities', 'verb' => 'POST'],
		['name' => 'service#userCredentials', 'url' => '/services/user-credentials', 'verb' => 'GET'],
		['name' => 'service#setUserCredentials', 'url' => '/services/{id}/user-credentials', 'verb' => 'PUT'],

		['name' => 'openAiAPI#getUserQuotaInfo', 'url' => '/quota-info', 'verb' => 'GET'],
		['name' => 'openAiAPI#getAdminQuotaInfo', 'url' => '/admin-quota-info', 'verb' => 'GET'],

		['name' => 'quotaRule#addRule', 'url' => '/quota/rule', 'verb' => 'POST'],
		['name' => 'quotaRule#updateRule', 'url' => '/quota/rule', 'verb' => 'PUT'],
		['name' => 'quotaRule#deleteRule', 'url' => '/quota/rule', 'verb' => 'DELETE'],
		['name' => 'quotaRule#getQuotaUsage', 'url' => '/quota/download-usage', 'verb' => 'GET'],
	],
];
