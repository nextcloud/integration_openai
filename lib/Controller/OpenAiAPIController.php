<?php
/**
 * Nextcloud - OpenAI
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2022
 */

namespace OCA\OpenAi\Controller;

use Exception;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class OpenAiAPIController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private OpenAiAPIService $openAiAPIService,
		private OpenAiSettingsService $openAiSettingsService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getModels(): DataResponse {
		try {
			$response = $this->openAiAPIService->getModels($this->userId);
		} catch (Exception $e) {
			$code = $e->getCode() === 0 ? Http::STATUS_BAD_REQUEST : intval($e->getCode());
			return new DataResponse(['error' => $e->getMessage()], $code);
		}

		$response['default_completion_model_id'] = $this->openAiSettingsService->getUserDefaultCompletionModelId($this->userId);
		return new DataResponse($response);
	}

	/**
	 * Get quota usage and limits
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getUserQuotaInfo(): DataResponse {
		try {
			$info = $this->openAiAPIService->getUserQuotaInfo($this->userId);
		} catch (Exception $e) {
			$code = $e->getCode() === 0 ? Http::STATUS_BAD_REQUEST : intval($e->getCode());
			return new DataResponse(['error' => $e->getMessage()], $code);
		}

		return new DataResponse($info);
	}

	/**
	 * Get quota usage and limits for the whole instance
	 * Admin only!
	 * @return DataResponse
	 */
	public function getAdminQuotaInfo(): DataResponse {
		try {
			$info = $this->openAiAPIService->getAdminQuotaInfo();
		} catch (Exception $e) {
			$code = $e->getCode() === 0 ? Http::STATUS_BAD_REQUEST : intval($e->getCode());
			return new DataResponse(['error' => $e->getMessage()], $code);
		}

		return new DataResponse($info);
	}
}
