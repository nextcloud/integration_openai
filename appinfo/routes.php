<?php
/**
 * Nextcloud - OpenAI
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2022
 */

return [
	'routes' => [
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
		['name' => 'openAiAPI#getModels', 'url' => '/models', 'verb' => 'GET'],
		['name' => 'openAiAPI#createCompletion', 'url' => '/completions', 'verb' => 'POST'],
		['name' => 'openAiAPI#createImage', 'url' => '/images/generations', 'verb' => 'POST'],
	],
];
