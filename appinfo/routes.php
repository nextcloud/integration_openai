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
		['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
		['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],

		['name' => 'openAiAPI#getModels', 'url' => '/models', 'verb' => 'GET'],
		['name' => 'openAiAPI#getPromptHistory', 'url' => '/prompts', 'verb' => 'GET'],
		['name' => 'openAiAPI#createCompletion', 'url' => '/completions', 'verb' => 'POST'],
		['name' => 'openAiAPI#createImage', 'url' => '/images/generations', 'verb' => 'POST'],
		['name' => 'openAiAPI#transcribe', 'url' => '/audio/transcriptions', 'verb' => 'POST'],
		['name' => 'openAiAPI#getImageGenerationContent', 'url' => '/images/generations/{hash}/{urlId}', 'verb' => 'GET'],
		['name' => 'openAiAPI#getImageGenerationPage', 'url' => '/i/{hash}', 'verb' => 'GET'],
	],
];
