<?php
/**
 * Nextcloud - OpenAI
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2022
 */

namespace OCA\OpenAi\Service;

use DateTime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\ImageGenerationMapper;
use OCA\OpenAi\Db\ImageUrlMapper;
use OCA\OpenAi\Db\PromptMapper;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\Db\Exception as DBException;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\NotPermittedException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service to make requests to OpenAI REST API
 */
class OpenAiAPIService {
	private IClient $client;

	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private ImageGenerationMapper $imageGenerationMapper,
		private ImageUrlMapper $imageUrlMapper,
		private PromptMapper $promptMapper,
		private QuotaUsageMapper $quotaUsageMapper,
		private OpenAiSettingsService $openAiSettingsService,
		IClientService $clientService
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * @return bool
	 */
	public function isUsingOpenAi(): bool {
		$serviceUrl = $this->openAiSettingsService->getServiceUrl();
		return $serviceUrl === '' || $serviceUrl === Application::OPENAI_API_BASE_URL;
	}

	/**
	 * @param string $userId
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getModels(string $userId): array {
		$response = $this->request($userId, 'models');
		if (!isset($response['data'])) {
			$this->logger->warning('Error retrieving models: ' . json_encode($response));
			throw new Exception($this->l10n->t('Unknown models error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return $response;
	}

	/**
	 * @param string $userId
	 * @param int $type
	 * @return array
	 * @throws Exception
	 */
	public function getPromptHistory(string $userId, int $type): array {
		try {
			return $this->promptMapper->getPromptsOfUser($userId, $type);
		} catch (DBException $e) {
			$this->logger->warning('Could not retrieve prompt history for user: ' . $userId . ' and prompt type: ' . $type . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			throw new Exception($this->l10n->t('Unknown error while retrieving prompt history.'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Clear prompt history for a prompt type
	 * @param string $userId
	 * @param int $type
	 * @throws Exception
	 */
	public function clearPromptHistory(string $userId, int $type): void {
		try {
			$this->promptMapper->deleteUserPromptsByType($userId, $type);
		} catch (DBException $e) {
			$this->logger->warning('Could not clear prompt history for user: ' . $userId . ' and prompt type: ' . $type . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			throw new Exception($this->l10n->t('Unknown error while clearing prompt history.'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @param string $userId
	 */
	private function hasOwnOpenAiApiKey(string $userId): bool {
		if (!$this->isUsingOpenAi()) {
			return false;
		}

		if ($this->openAiSettingsService->getUserApiKey($userId) !== '') {
			return true;
		}

		return false;
	}


	/**
	 * Check whether quota is exceeded for a user
	 * @param string|null $userId
	 * @param int $type
	 * @return bool
	 * @throws Exception
	 */
	public function isQuotaExceeded(?string $userId, int $type): bool {
		if ($userId === null) {
			return false;
		}

		if (!array_key_exists($type, Application::DEFAULT_QUOTAS)) {
			throw new Exception('Invalid quota type', Http::STATUS_BAD_REQUEST);
		}

		if ($this->hasOwnOpenAiApiKey($userId)) {
			// User has specified own OpenAI API key, no quota limit:
			return false;
		}

		// Get quota limits
		$quota = $this->openAiSettingsService->getQuotas()[$type];

		if ($quota === 0) {
			//  Unlimited quota:
			return false;
		}

		$quotaPeriod = $this->openAiSettingsService->getQuotaPeriod();

		try {
			$quotaUsage = $this->quotaUsageMapper->getQuotaUnitsOfUserInTimePeriod($userId, $type, $quotaPeriod);
		} catch (DoesNotExistException | MultipleObjectsReturnedException | DBException | RuntimeException $e) {
			$this->logger->warning('Could not retrieve quota usage for user: ' . $userId . ' and quota type: ' . $type . '. Error: ' . $e->getMessage());
			throw new Exception('Could not retrieve quota usage.', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $quotaUsage >= $quota;
	}

	/**
	 * Translate the quota type
	 * @param int $type
	 */
	public function translatedQuotaType(int $type): string {
		switch ($type) {
			case Application::QUOTA_TYPE_TEXT:
				return $this->l10n->t('Text generation');
			case Application::QUOTA_TYPE_IMAGE:
				return $this->l10n->t('Image generation');
			case Application::QUOTA_TYPE_TRANSCRIPTION:
				return $this->l10n->t('Audio transcription');
			default:
				return $this->l10n->t('Unknown');
		}
	}

	/**
	 * Get translated unit of quota type
	 * @param int $type
	 */
	public function translatedQuotaUnit(int $type): string {
		switch ($type) {
			case Application::QUOTA_TYPE_TEXT:
				return $this->l10n->t('tokens');
			case Application::QUOTA_TYPE_IMAGE:
				return $this->l10n->t('images');
			case Application::QUOTA_TYPE_TRANSCRIPTION:
				return $this->l10n->t('seconds');
			default:
				return $this->l10n->t('Unknown');
		}
	}

	/**
	 * @param string $userId
	 * @return array
	 * @throws Exception
	 */
	public function getUserQuotaInfo(string $userId): array {
		// Get quota limits (if the user has specified an own OpenAI API key, no quota limit, just supply default values as fillers)
		$quotas = $this->hasOwnOpenAiApiKey($userId) ? Application::DEFAULT_QUOTAS : $this->openAiSettingsService->getQuotas();
		// Get quota period
		$quotaPeriod = $this->openAiSettingsService->getQuotaPeriod();
		// Get quota usage for each quota type:
		$quotaInfo = [];
		foreach (Application::DEFAULT_QUOTAS as $quotaType => $_) {
			$quotaInfo[$quotaType]['type'] = $this->translatedQuotaType($quotaType);
			try {
				$quotaInfo[$quotaType]['used'] = $this->quotaUsageMapper->getQuotaUnitsOfUserInTimePeriod($userId, $quotaType, $quotaPeriod);
			} catch (DoesNotExistException | MultipleObjectsReturnedException | DBException | RuntimeException $e) {
				$this->logger->warning('Could not retrieve quota usage for user: ' . $userId . ' and quota type: ' . $quotaType . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				throw new Exception($this->l10n->t('Unknown error while retrieving quota usage.', Http::STATUS_INTERNAL_SERVER_ERROR));
			}
			$quotaInfo[$quotaType]['limit'] = intval($quotas[$quotaType]);
			$quotaInfo[$quotaType]['unit'] = $this->translatedQuotaUnit($quotaType);
		}

		return [
			'quota_usage' => $quotaInfo,
			'period' => $quotaPeriod,
		];
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	public function getAdminQuotaInfo(): array {
		// Get quota period
		$quotaPeriod = $this->openAiSettingsService->getQuotaPeriod();
		// Get quota usage of all users for each quota type:
		$quotaInfo = [];
		foreach (Application::DEFAULT_QUOTAS as $quotaType => $_) {
			$quotaInfo[$quotaType]['type'] = $this->translatedQuotaType($quotaType);
			try {
				$quotaInfo[$quotaType]['used'] = $this->quotaUsageMapper->getQuotaUnitsInTimePeriod($quotaType, $quotaPeriod);
			} catch (DoesNotExistException | MultipleObjectsReturnedException | DBException | RuntimeException $e) {
				$this->logger->warning('Could not retrieve quota usage for quota type: ' . $quotaType . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				// We can pass detailed error info to the UI here since the user is an admin in any case:
				throw new Exception('Could not retrieve quota usage: ' . $e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
			}
			$quotaInfo[$quotaType]['unit'] = $this->translatedQuotaUnit($quotaType);
		}

		return $quotaInfo;
	}

	/**
	 * @param string|null $userId
	 * @param string $prompt
	 * @param int $n
	 * @param string $model
	 * @param int $maxTokens
	 * @param bool $storePrompt
	 * @return array|string[]
	 * @throws Exception
	 */
	public function createCompletion(
		?string $userId,
		string $prompt,
		int $n,
		string $model,
		int $maxTokens = 1000,
		bool $storePrompt = true
	): array {

		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TEXT)) {
			throw new Exception($this->l10n->t('Text generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$maxTokensLimit = $this->openAiSettingsService->getMaxTokens();
		if ($maxTokens > $maxTokensLimit) {
			$maxTokens = $maxTokensLimit;
		}

		$params = [
			'model' => $model,
			'prompt' => $prompt,
			'max_tokens' => $maxTokens,
			'n' => $n,
		];
		if ($userId !== null) {
			$params['user'] = $userId;
		}
		$response = $this->request($userId, 'completions', $params, 'POST');

		if (!isset($response['choices'])) {
			$this->logger->warning('Text generation error: ' . json_encode($response));
			throw new Exception($this->l10n->t('Unknown text generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (isset($response['usage'])) {
			$usage = $response['usage']['total_tokens'];
			try {
				$this->quotaUsageMapper->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TEXT . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}

			if ($userId !== null && $storePrompt) {
				try {
					$this->promptMapper->createPrompt(Application::PROMPT_TYPE_TEXT, $userId, $prompt);
				} catch (DBException $e) {
					$this->logger->warning('Could not store prompt for user: ' . $userId . ' and prompt: ' . $prompt . '. Error: ' . $e->getMessage());
				}
			}
		}
		$completions = [];

		foreach ($response['choices'] as $choice) {
			$completions[] = $choice['text'];
		}

		return $completions;
	}

	/**
	 * Returns an array of completions
	 *
	 * @param string|null $userId
	 * @param string $prompt
	 * @param int $n
	 * @param string $model
	 * @param int $maxTokens
	 * @param bool $storePrompt
	 * @return string[]
	 * @throws Exception
	 */
	public function createChatCompletion(
		?string $userId,
		string $prompt,
		int $n,
		string $model,
		int $maxTokens = 1000,
		bool $storePrompt = true
	): array {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TEXT)) {
			throw new Exception($this->l10n->t('Text generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$maxTokensLimit = $this->openAiSettingsService->getMaxTokens();
		if ($maxTokens > $maxTokensLimit) {
			$maxTokens = $maxTokensLimit;
		}

		$params = [
			'model' => $model,
			'messages' => [['role' => 'user', 'content' => $prompt]],
			'max_tokens' => $maxTokens,
			'n' => $n,
		];
		if ($userId !== null) {
			$params['user'] = $userId;
		}

		$response = $this->request($userId, 'chat/completions', $params, 'POST');

		if (!isset($response['choices'])) {
			$this->logger->warning('Text generation error: ' . json_encode($response));
			throw new Exception($this->l10n->t('Unknown text generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (isset($response['usage'])) {
			$usage = $response['usage']['total_tokens'];

			try {
				$this->quotaUsageMapper->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TEXT . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}


			if ($userId !== null && $storePrompt) {
				try {
					$this->promptMapper->createPrompt(Application::PROMPT_TYPE_TEXT, $userId, $prompt);
				} catch (DBException $e) {
					$this->logger->warning('Could not store prompt for user: ' . $userId . ' and prompt: ' . $prompt . '. Error: ' . $e->getMessage());
				}

			}
		}
		$completions = [];

		foreach ($response['choices'] as $choice) {
			$completions[] = $choice['message']['content'];
		}

		return $completions;
	}

	/**
	 * @param string|null $userId
	 * @param string $audioBase64
	 * @param bool $translate
	 * @return string
	 * @throws Exception
	 */
	public function transcribeBase64Mp3(
		?string $userId,
		string $audioBase64,
		bool $translate = true,
		string $model = Application::DEFAULT_TRANSCRIPTION_MODEL_ID
	): string {
		return $this->transcribe(
			$userId,
			base64_decode(str_replace('data:audio/mp3;base64,', '', $audioBase64)),
			$translate,
			$model
		);
	}

	/**
	 * @param string|null $userId
	 * @param File $file
	 * @param bool $translate
	 * @return string
	 * @throws Exception
	 */
	public function transcribeFile(
		?string $userId,
		File $file,
		bool $translate = false,
		string $model = Application::DEFAULT_TRANSCRIPTION_MODEL_ID
	): string {
		try {
			$transcriptionResponse = $this->transcribe($userId, $file->getContent(), $translate, $model);
		} catch (NotPermittedException | LockedException | GenericFileException $e) {
			$this->logger->warning('Could not read audio file: ' . $file->getPath() . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			throw new Exception($this->l10n->t('Could not read audio file.'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $transcriptionResponse;
	}

	/**
	 * @param string|null $userId
	 * @param string $audioFileContent
	 * @param bool $translate
	 * @param string $model
	 * @return string
	 * @throws Exception
	 */
	public function transcribe(
		?string $userId,
		string $audioFileContent,
		bool $translate = true,
		string $model = Application::DEFAULT_TRANSCRIPTION_MODEL_ID
	): string {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TRANSCRIPTION)) {
			throw new Exception($this->l10n->t('Audio transcription quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$params = [
			'model' => $model,
			'file' => $audioFileContent,
			'response_format' => 'verbose_json',
			// Verbose needed for extraction of audio duration
		];
		$endpoint = $translate ? 'audio/translations' : 'audio/transcriptions';
		$contentType = 'multipart/form-data';

		$response = $this->request($userId, $endpoint, $params, 'POST', $contentType);

		if (!isset($response['text'])) {
			$this->logger->warning('Audio transcription error: ' . json_encode($response));
			throw new Exception($this->l10n->t('Unknown audio trancription error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// Extract audio duration from response and store it as quota usage:
		if (isset($response['segments'])) {
			$audioDuration = intval(round(floatval(array_pop($response['segments'])['end'])));

			try {
				$this->quotaUsageMapper->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TRANSCRIPTION, $audioDuration);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TRANSCRIPTION . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		return $response['text'];
	}

	/**
	 * @param string|null $userId
	 * @param string $prompt
	 * @param int $n
	 * @param string $size
	 * @return array|string[]
	 * @throws Exception
	 */
	public function createImage(?string $userId, string $prompt, int $n = 1, string $size = Application::DEFAULT_IMAGE_SIZE): array {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_IMAGE)) {
			throw new Exception($this->l10n->t('Image generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		if ($userId !== null) {
			$this->openAiSettingsService->setLastImageSize($userId, $size);
		}

		$params = [
			'prompt' => $prompt,
			'size' => $size,
			'n' => $n,
			'response_format' => 'url',
		];

		if ($userId !== null) {
			$params['user'] = $userId;
		}

		$apiResponse = $this->request($userId, 'images/generations', $params, 'POST');

		if (!isset($apiResponse['data']) || !is_array($apiResponse['data'])) {
			$this->logger->warning('OpenAI image generation error: ' . json_encode($apiResponse));
			throw new Exception($this->l10n->t('Unknown image generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);

		} else {
			try {
				$this->promptMapper->createPrompt(Application::PROMPT_TYPE_IMAGE, $userId, $prompt);
			} catch (DBException $e) {
				$this->logger->warning('Could not store prompt for user: ' . $userId . ' and prompt: ' . $prompt . '. Error: ' . $e->getMessage());
			}

			try {
				$this->quotaUsageMapper->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_IMAGE, $n);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_IMAGE . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}


			$urls = array_map(static function (array $result) {
				return $result['url'] ?? null;
			}, $apiResponse['data']);
			$urls = array_filter($urls, static function (?string $url) {
				return $url !== null;
			});
			$urls = array_values($urls);
			if (!empty($urls)) {
				$hash = md5(implode('|', $urls));
				$ts = (new DateTime())->getTimestamp();
				try {
					$this->imageGenerationMapper->createImageGeneration($hash, $prompt, $ts, $urls);
				} catch (DBException $e) {
					$this->logger->warning('Could not create image generation for hash: ' . $hash . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
					throw new Exception($this->l10n->t('Unknown image generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);
				}

				return ['hash' => $hash];
			}
		}

		return $apiResponse;
	}

	/**
	 * @param string $hash
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getGenerationInfo(string $hash): array {
		try {
			$imageGeneration = $this->imageGenerationMapper->getImageGenerationFromHash($hash);
			$imageUrls = $this->imageUrlMapper->getImageUrlsOfGeneration($imageGeneration->getId());
			$this->imageGenerationMapper->touchImageGeneration($imageGeneration->getId());
			return [
				'hash' => $hash,
				'prompt' => $imageGeneration->getPrompt(),
				'urls' => $imageUrls,
			];
		} catch (DoesNotExistException $e) {
			$this->logger->debug('Image generation info request error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
			throw new Exception($this->l10n->t('Image generation not found'), Http::STATUS_NOT_FOUND);
		} catch (Exception | DBException | MultipleObjectsReturnedException $e) {
			$this->logger->debug('Image generation info request error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
			throw new Exception($this->l10n->t('Unknown image generation request error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @param string $hash
	 * @param int $urlId
	 * @return array|null
	 * @throws Exception
	 */
	public function getGenerationImage(string $hash, int $urlId): ?array {
		try {
			$imageGeneration = $this->imageGenerationMapper->getImageGenerationFromHash($hash);
			$imageUrl = $this->imageUrlMapper->getImageUrlOfGeneration($imageGeneration->getId(), $urlId);
			$imageResponse = $this->client->get($imageUrl->getUrl());
			return [
				'body' => $imageResponse->getBody(),
				'headers' => $imageResponse->getHeaders(),
			];
		} catch (DoesNotExistException $e) {
			$this->logger->debug('Image request error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
			throw new Exception($this->l10n->t('Image not found'), Http::STATUS_NOT_FOUND);
		} catch (Exception | DBException | MultipleObjectsReturnedException $e) {
			$this->logger->debug('Image request error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
			throw new Exception($this->l10n->t('Unknown image request error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Make an HTTP request to the OpenAI API
	 * @param string|null $userId
	 * @param string $endPoint The path to reach
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @param string|null $contentType
	 * @return array decoded request result or error
	 * @throws Exception
	 */
	public function request(?string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $contentType = null): array {
		try {
			$serviceUrl = $this->openAiSettingsService->getServiceUrl();
			if ($serviceUrl === '') {
				$serviceUrl = Application::OPENAI_API_BASE_URL;
			}
			
			$timeout = $this->openAiSettingsService->getRequestTimeout();
			$timeout = (int) $timeout;

			$url = $serviceUrl . '/v1/' . $endPoint;
			$options = [
				'timeout' => $timeout,
				'headers' => [
					'User-Agent' => 'Nextcloud OpenAI integration',
				],
			];

			// an API key is mandatory when using OpenAI
			$apiKey = $this->openAiSettingsService->getUserApiKey($userId, true);

			// We can also use basic authentication
			$basicUser = $this->openAiSettingsService->getUserBasicUser($userId, true);
			$basicPassword = $this->openAiSettingsService->getUserBasicPassword($userId, true);

			if ($serviceUrl === Application::OPENAI_API_BASE_URL && $apiKey === '') {
				return ['error' => 'An API key is required for api.openai.com'];
			}

			$useBasicAuth = $this->openAiSettingsService->getUseBasicAuth();

			if ($this->isUsingOpenAi() || !$useBasicAuth) {
				if ($apiKey !== '') {
					$options['headers']['Authorization'] = 'Bearer ' . $apiKey;
				}				
			} elseif ($useBasicAuth) {
				if ($basicUser !== '' && $basicPassword !== '') {
					$options['headers']['Authorization'] = 'Basic ' . base64_encode($basicUser . ':' . $basicPassword);	
				}				
			}

			if ($contentType === null) {
				$options['headers']['Content-Type'] = 'application/json';
			} elseif ($contentType === 'multipart/form-data') {
				// no header in this case
				// $options['headers']['Content-Type'] = $contentType;
			} else {
				$options['headers']['Content-Type'] = $contentType;
			}

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					if ($contentType === 'multipart/form-data') {
						$multipart = [];
						foreach ($params as $key => $value) {
							$part = [
								'name' => $key,
								'contents' => $value,
							];
							if ($key === 'file') {
								$part['filename'] = 'file.mp3';
							}
							$multipart[] = $part;
						}
						$options['multipart'] = $multipart;
					} else {
						$options['body'] = json_encode($params);
					}
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true) ?: [];
			}
		} catch (ClientException | ServerException $e) {
			$responseBody = $e->getResponse()->getBody();
			$parsedResponseBody = json_decode($responseBody, true);
			if ($e->getResponse()->getStatusCode() === 404) {
				$this->logger->debug('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			} else {
				$this->logger->warning('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			}
			if (isset($parsedResponseBody['error']) && isset($parsedResponseBody['error']['message'])) {
				throw new Exception($this->l10n->t('API request error: ') . $parsedResponseBody['error']['message'], $e->getCode());
			} else {
				throw new Exception($this->l10n->t('API request error: ') . $e->getMessage(), $e->getCode());
			}
		} catch (Exception | Throwable $e) {
			$this->logger->warning('API request error : ' . $e->getMessage(), ['exception' => $e]);
			throw new Exception($this->l10n->t('Unknown API request error.'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
