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
	 * @param string $prompt
	 * @return DataResponse
	 */
	public function createCompletion(string $prompt): DataResponse {
		$response = $this->openAiAPIService->createCompletion($prompt);
		if (isset($response['error'])) {
			return new DataResponse($response, Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($response);
	}

	/**
	 * @NoAdminRequired
	 * @param string $prompt
	 * @return DataResponse
	 */
	public function createImage(string $prompt, int $n = 1, string $size = '1024x1024'): DataResponse {
		$response = $this->openAiAPIService->createImage($this->userId, $prompt, $n, $size);
		if (isset($response['error'])) {
			return new DataResponse($response, Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($response);
	}
}
