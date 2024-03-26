<?php
/**
 * Nextcloud - OpenAI
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2023
 */

return [
	'routes' => [
		['name' => 'config#setUserConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		['name' => 'openAiAPI#getModels', 'url' => '/models', 'verb' => 'GET'],
		['name' => 'openAiAPI#getUserQuotaInfo', 'url' => '/quota-info', 'verb' => 'GET'],
		['name' => 'openAiAPI#getAdminQuotaInfo', 'url' => '/admin-quota-info', 'verb' => 'GET'],
	],
];
