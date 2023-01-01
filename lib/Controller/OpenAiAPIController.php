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

namespace OCA\OpenAi\Controller;

use OCA\OpenAi\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

use OCA\OpenAi\Service\OpenAiAPIService;

class OpenAiAPIController extends Controller {

	private OpenAiAPIService $openAiAPIService;
	private IInitialState $initialStateService;
	private ?string $userId;

	public function __construct(string           $appName,
								IRequest         $request,
								OpenAiAPIService $openAiAPIService,
								IInitialState    $initialStateService,
								?string          $userId) {
		parent::__construct($appName, $request);
		$this->openAiAPIService = $openAiAPIService;
		$this->initialStateService = $initialStateService;
		$this->userId = $userId;
	}

	/**
	 * @NoAdminRequired
	 * @return DataResponse
	 */
	public function getModels(): DataResponse {
		$response = $this->openAiAPIService->getModels();
		if (isset($response['error'])) {
			return new DataResponse($response, Http::STATUS_BAD_REQUEST);
		}
		$response['default_model_id'] = Application::DEFAULT_COMPLETION_MODEL;
		return new DataResponse($response);
	}

	/**
	 * @NoAdminRequired
	 * @param string $prompt
	 * @param int $n
	 * @param string $model
	 * @return DataResponse
	 */
	public function createCompletion(string $prompt, int $n = 1, string $model = Application::DEFAULT_COMPLETION_MODEL): DataResponse {
		$response = $this->openAiAPIService->createCompletion($this->userId, $prompt, $n, $model);
		if (isset($response['error'])) {
			return new DataResponse($response, Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($response);
	}

	/**
	 * @NoAdminRequired
	 * @param string $prompt
	 * @param int $n
	 * @param string $size
	 * @return DataResponse
	 */
	public function createImage(string $prompt, int $n = 1, string $size = Application::DEFAULT_IMAGE_SIZE): DataResponse {
		$response = $this->openAiAPIService->createImage($this->userId, $prompt, $n, $size);
		if (isset($response['error'])) {
			return new DataResponse($response, Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($response);
	}
}
