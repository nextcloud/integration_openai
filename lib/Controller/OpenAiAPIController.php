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
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\DB\Exception as DBException;
use OCP\IL10N;

use OCP\IRequest;
use Psr\Log\LoggerInterface;

class OpenAiAPIController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private OpenAiAPIService $openAiAPIService,
		private OpenAiSettingsService $openAiSettingsService,
		private IInitialState $initialStateService,
		private ?string $userId,
		private LoggerInterface $logger,
		private IL10N $l10n
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
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$response['default_completion_model_id'] = $this->openAiSettingsService->getUserDefaultCompletionModelId($this->userId);
		return new DataResponse($response);
	}

	/**
	 * @param int $type
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getPromptHistory(int $type): DataResponse {
		try {
			$response = $this->openAiAPIService->getPromptHistory($this->userId, $type);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($response);
	}

	/**
	 * @param string $prompt
	 * @param int $n
	 * @param string|null $model
	 * @param int $maxTokens
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function createCompletion(string $prompt, int $n = 1, ?string $model = null, int $maxTokens = 1000): DataResponse {
		if ($model === null) {
			$model = $this->openAiSettingsService->getUserDefaultCompletionModelId($this->userId);
		}
		try {
			if (str_starts_with($model, 'gpt-')) {
				$response = $this->openAiAPIService->createChatCompletion($this->userId, $prompt, $n, $model, $maxTokens);
			} else {
				$response = $this->openAiAPIService->createCompletion($this->userId, $prompt, $n, $model, $maxTokens);
			}
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($response);
	}

	/**
	 * @param string $audioBase64
	 * @param bool $translate
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function transcribe(string $audioBase64, bool $translate = true): DataResponse {
		try {
			$response = $this->openAiAPIService->transcribeBase64Mp3($this->userId, $audioBase64, $translate);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse(['text' => $response]);
	}

	/**
	 * @param string $prompt
	 * @param int $n
	 * @param string $size
	 */
	#[NoAdminRequired]
	public function createImage(string $prompt, int $n = 1, string $size = Application::DEFAULT_IMAGE_SIZE): DataResponse {

		try {
			$response = $this->openAiAPIService->createImage($this->userId, $prompt, $n, $size);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($response);
	}

	/**
	 * @param string $hash
	 * @param int $urlId
	 * @return DataDisplayResponse
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getImageGenerationContent(string $hash, int $urlId): DataDisplayResponse {
		try {
			$image = $this->openAiAPIService->getGenerationImage($hash, $urlId);
		} catch (Exception $e) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}

		if ($image !== null && isset($image['body'], $image['headers'])) {
			$response = new DataDisplayResponse(
				$image['body'],
				Http::STATUS_OK,
				['Content-Type' => $image['headers']['Content-Type'][0] ?? 'image/jpeg']
			);
			$response->cacheFor(60 * 60 * 24, false, true);
			return $response;
		}
		return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
	}

	/**
	 * @param string $hash
	 * @return TemplateResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getImageGenerationPage(string $hash): TemplateResponse {
		try {
			$generationData = $this->openAiAPIService->getGenerationInfo($hash);
		} catch (Exception $e) {
			$generationData['error'] = $e->getMessage();
		}
		$this->initialStateService->provideInitialState('generation', $generationData);
		return new TemplateResponse(Application::APP_ID, 'imageGenerationPage');
	}

	/**
	 * @param bool|null $clearTextPrompts
	 * @param bool|null $clearImagePrompts
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function clearPromptHistory(?bool $clearTextPrompts = null, ?bool $clearImagePrompts = null): DataResponse {
		$this->logger->debug('Clearing prompt history: ' . 'clearTextPrompts ' . strval($clearTextPrompts) . ' clearImagePrompts ' . strval($clearImagePrompts));
		if ($clearTextPrompts === true) {
			try {
				$this->openAiAPIService->clearPromptHistory($this->userId, Application::PROMPT_TYPE_TEXT);
			} catch (DBException $e) {
				return new DataResponse($this->l10n->t('Unknown error while clearing prompt history'), Http::STATUS_BAD_REQUEST);
			}
		}

		if ($clearImagePrompts === true) {
			try {
				$this->openAiAPIService->clearPromptHistory($this->userId, Application::PROMPT_TYPE_IMAGE);
			} catch (DBException $e) {
				return new DataResponse($this->l10n->t('Unknown error while clearing prompt history'), Http::STATUS_BAD_REQUEST);
			}
		}

		return new DataResponse(['status' => 'success']);
	}

	/**
	 * Get quota usage and limits
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getUserQuotaInfo(): DataResponse {
		$info = $this->openAiAPIService->getUserQuotaInfo($this->userId);

		return new DataResponse($info);
	}

	/**
	 * Get quota usage and limits for the whole instance
	 * Admin only!
	 * @return DataResponse
	 */
	public function getAdminQuotaInfo(): DataResponse {
		$info = $this->openAiAPIService->getAdminQuotaInfo();

		return new DataResponse($info);
	}
}
