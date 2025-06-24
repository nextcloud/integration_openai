<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\OpenAi\AppInfo\Application;
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
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\Lock\LockedException;
use OCP\TaskProcessing\ShapeEnumValue;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use function json_encode;

/**
 * Service to make requests to OpenAI/LocalAI REST API
 */
class OpenAiAPIService {
	private IClient $client;
	private ?array $modelsMemoryCache = null;
	private ?bool $areCredsValid = null;

	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IAppConfig $appConfig,
		private ICacheFactory $cacheFactory,
		private QuotaUsageMapper $quotaUsageMapper,
		private OpenAiSettingsService $openAiSettingsService,
		IClientService $clientService,
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
	 * @return string
	 */
	public function getServiceName(): string {
		if ($this->isUsingOpenAi()) {
			return 'OpenAI';
		} else {
			$serviceName = $this->openAiSettingsService->getServiceName();
			if ($serviceName === '') {
				return 'LocalAI';
			}
			return $serviceName;
		}
	}

	/**
	 * @param mixed $models
	 * @return boolean
	 */
	private function isModelListValid($models): bool {
		if (!is_array($models) || !array_is_list($models)) {
			return false;
		}
		if (count($models) === 0) {
			return false;
		}
		foreach ($models as $model) {
			if (!isset($model['id'])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $userId
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getModels(string $userId): array {
		// caching against 'getModelEnumValues' calls from all the providers
		if ($this->areCredsValid === false) {
			$this->logger->info('Cannot get OpenAI models without an API key');
			return [];
		} elseif ($this->areCredsValid === null) {
			if ($this->isUsingOpenAi() && $this->openAiSettingsService->getUserApiKey($userId, true) === '') {
				$this->areCredsValid = false;
				$this->logger->info('Cannot get OpenAI models without an API key');
				return [];
			}
			$this->areCredsValid = true;
		}

		if ($this->modelsMemoryCache !== null) {
			$this->logger->debug('Getting OpenAI models from the memory cache');
			return $this->modelsMemoryCache;
		}

		$cacheKey = Application::MODELS_CACHE_KEY;
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		if ($cachedModels = $cache->get($cacheKey)) {
			$this->logger->debug('Getting OpenAI models from distributed cache');
			return $cachedModels;
		}

		try {
			$this->logger->debug('Actually getting OpenAI models with a network request');
			$modelsResponse = $this->request($userId, 'models');
		} catch (Exception $e) {
			$this->logger->warning('Error retrieving models (exc): ' . $e->getMessage());
			$this->areCredsValid = false;
			throw $e;
		}
		if (isset($modelsResponse['error'])) {
			$this->logger->warning('Error retrieving models: ' . json_encode($modelsResponse));
			$this->areCredsValid = false;
			throw new Exception($modelsResponse['error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if (!isset($modelsResponse['data'])) {
			// also consider responses without 'data' as valid
			$modelsResponse = ['data' => $modelsResponse];
		}

		if (!$this->isModelListValid($modelsResponse['data'])) {
			$this->logger->warning('Invalid models response: ' . json_encode($modelsResponse));
			$this->areCredsValid = false;
			throw new Exception($this->l10n->t('Invalid models response received'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$cache->set($cacheKey, $modelsResponse, Application::MODELS_CACHE_TTL);
		$this->modelsMemoryCache = $modelsResponse;
		$this->areCredsValid = true;
		return $modelsResponse;
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
	 * @param string|null $userId
	 * @return array
	 */
	public function getModelEnumValues(?string $userId): array {
		if ($userId === null) {
			return [];
		}
		try {
			$modelResponse = $this->getModels($userId);
			$modelEnumValues = array_map(function (array $model) {
				return new ShapeEnumValue($model['id'], $model['id']);
			}, $modelResponse['data'] ?? []);
			if ($this->isUsingOpenAi()) {
				array_unshift($modelEnumValues, new ShapeEnumValue($this->l10n->t('Default'), 'Default'));
			}
			return $modelEnumValues;
		} catch (Throwable $e) {
			// avoid flooding the logs with errors from calls of task processing
			$this->logger->info('Error getting model enum values', ['exception' => $e]);
			return [];
		}
	}

	/**
	 * Check whether quota is exceeded for a user
	 *
	 * @param string|null $userId
	 * @param int $type
	 * @return bool
	 * @throws Exception
	 */
	public function isQuotaExceeded(?string $userId, int $type): bool {
		if ($userId === null) {
			$this->logger->warning('Cannot check quota for anonymous user', ['app' => Application::APP_ID]);
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
		} catch (DoesNotExistException|MultipleObjectsReturnedException|DBException|RuntimeException $e) {
			$this->logger->warning('Could not retrieve quota usage for user: ' . $userId . ' and quota type: ' . $type . '. Error: ' . $e->getMessage());
			throw new Exception('Could not retrieve quota usage.', Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $quotaUsage >= $quota;
	}

	/**
	 * Translate the quota type
	 *
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
			case Application::QUOTA_TYPE_SPEECH:
				return $this->l10n->t('Text to speech');
			default:
				return $this->l10n->t('Unknown');
		}
	}

	/**
	 * Get translated unit of quota type
	 *
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
			case Application::QUOTA_TYPE_SPEECH:
				return $this->l10n->t('characters');
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
			} catch (DoesNotExistException|MultipleObjectsReturnedException|DBException|RuntimeException $e) {
				$this->logger->warning('Could not retrieve quota usage for user: ' . $userId . ' and quota type: ' . $quotaType . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				throw new Exception($this->l10n->t('Unknown error while retrieving quota usage.'), Http::STATUS_INTERNAL_SERVER_ERROR);
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
			} catch (DoesNotExistException|MultipleObjectsReturnedException|DBException|RuntimeException $e) {
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
	 * @param int|null $maxTokens
	 * @param array|null $extraParams
	 * @return string[]
	 * @throws Exception
	 */
	public function createCompletion(
		?string $userId,
		string $prompt,
		int $n,
		string $model,
		?int $maxTokens = null,
		?array $extraParams = null,
	): array {

		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TEXT)) {
			throw new Exception($this->l10n->t('Text generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$maxTokensLimit = $this->openAiSettingsService->getMaxTokens();
		if ($maxTokens === null || $maxTokens > $maxTokensLimit) {
			$maxTokens = $maxTokensLimit;
		}

		$params = [
			'model' => $model === Application::DEFAULT_MODEL_ID ? Application::DEFAULT_COMPLETION_MODEL_ID : $model,
			'prompt' => $prompt,
			'max_tokens' => $maxTokens,
			'n' => $n,
		];
		if ($userId !== null) {
			$params['user'] = $userId;
		}

		$adminExtraParams = $this->getAdminExtraParams('llm_extra_params');
		if ($adminExtraParams !== null) {
			$params = array_merge($adminExtraParams, $params);
		}
		if ($extraParams !== null) {
			$params = array_merge($extraParams, $params);
		}

		$response = $this->request($userId, 'completions', $params, 'POST');

		if (!isset($response['choices'])) {
			$this->logger->warning('Text generation error: ' . json_encode($response));
			throw new Exception($this->l10n->t('Unknown text generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (isset($response['usage'], $response['usage']['total_tokens'])) {
			$usage = $response['usage']['total_tokens'];
			try {
				$this->quotaUsageMapper->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TEXT . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		$completions = [];

		foreach ($response['choices'] as $choice) {
			if (!isset($choice['text']) || !is_string($choice['text'])) {
				$this->logger->debug('Text generation yielded empty or invalid response: ' . json_encode($choice));
				continue;
			}
			$completions[] = $choice['text'];
		}

		return $completions;
	}

	/**
	 * Returns an array of completions
	 *
	 * @param string|null $userId
	 * @param string $model
	 * @param string|null $userPrompt
	 * @param string|null $systemPrompt
	 * @param array|null $history
	 * @param int $n
	 * @param int|null $maxTokens
	 * @param array|null $extraParams
	 * @param string|null $toolMessage JSON string with role, content, tool_call_id
	 * @param array|null $tools
	 * @return array{messages: array<string>, tool_calls: array<string>}
	 * @throws Exception
	 */
	public function createChatCompletion(
		?string $userId,
		string $model,
		?string $userPrompt = null,
		?string $systemPrompt = null,
		?array $history = null,
		int $n = 1,
		?int $maxTokens = null,
		?array $extraParams = null,
		?string $toolMessage = null,
		?array $tools = null,
	): array {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TEXT)) {
			throw new Exception($this->l10n->t('Text generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$modelRequestParam = $model === Application::DEFAULT_MODEL_ID
			? Application::DEFAULT_COMPLETION_MODEL_ID
			: $model;

		$messages = [];
		if ($systemPrompt !== null) {
			$messages[] = [
				// o1-* models don't support system messages
				// system prompts as a user message seems to work fine though
				'role' => ($this->isUsingOpenAi() && str_starts_with($modelRequestParam, 'o1-'))
					? 'user'
					: 'system',
				'content' => $systemPrompt,
			];
		}
		if ($history !== null) {
			foreach ($history as $i => $historyEntry) {
				$message = json_decode($historyEntry, true);
				if ($message['role'] === 'human') {
					$message['role'] = 'user';
				}
				if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
					$message['tool_calls'] = array_map(static function ($toolCall) {
						$formattedToolCall = [
							'id' => $toolCall['id'],
							'type' => 'function',
							'function' => $toolCall,
						];
						$formattedToolCall['function']['arguments'] = json_encode($toolCall['args']);
						unset($formattedToolCall['function']['id']);
						unset($formattedToolCall['function']['args']);
						unset($formattedToolCall['function']['type']);
						return $formattedToolCall;
					}, $message['tool_calls']);
				}
				$messages[] = $message;
			}
		}
		if ($userPrompt !== null) {
			$messages[] = ['role' => 'user', 'content' => $userPrompt];
		}
		if ($toolMessage !== null) {
			$msgs = json_decode($toolMessage, true);
			foreach ($msgs as $msg) {
				$msg['role'] = 'tool';
				$messages[] = $msg;
			}
		}

		$params = [
			'model' => $modelRequestParam,
			'messages' => $messages,
			'n' => $n,
		];

		$maxTokensLimit = $this->openAiSettingsService->getMaxTokens();
		if ($maxTokens === null || $maxTokens > $maxTokensLimit) {
			$maxTokens = $maxTokensLimit;
		}
		if ($this->openAiSettingsService->getUseMaxCompletionTokensParam()) {
			// max_tokens is now deprecated https://platform.openai.com/docs/api-reference/chat/create
			$params['max_completion_tokens'] = $maxTokens;
		} else {
			$params['max_tokens'] = $maxTokens;
		}

		if ($tools !== null) {
			$params['tools'] = $tools;
		}
		if ($userId !== null && $this->isUsingOpenAi()) {
			$params['user'] = $userId;
		}

		$adminExtraParams = $this->getAdminExtraParams('llm_extra_params');
		if ($adminExtraParams !== null) {
			$params = array_merge($adminExtraParams, $params);
		}
		if ($extraParams !== null) {
			$params = array_merge($extraParams, $params);
		}

		$response = $this->request($userId, 'chat/completions', $params, 'POST');

		if (!isset($response['choices'])) {
			$this->logger->warning('Text generation error: ' . json_encode($response));
			throw new Exception($this->l10n->t('Unknown text generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (isset($response['usage'], $response['usage']['total_tokens'])) {
			$usage = $response['usage']['total_tokens'];
			try {
				$this->quotaUsageMapper->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TEXT . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		$completions = [
			'messages' => [],
			'tool_calls' => [],
		];

		foreach ($response['choices'] as $choice) {
			// get tool calls only if this is the finish reason and it's defined and it's an array
			if ($choice['finish_reason'] === 'tool_calls'
				&& isset($choice['message']['tool_calls'])
				&& is_array($choice['message']['tool_calls'])
			) {
				// fix the tool_calls format, make it like expected by the context_agent app
				$choice['message']['tool_calls'] = array_map(static function ($toolCall) {
					$toolCall['function']['id'] = $toolCall['id'];
					$toolCall['function']['args'] = json_decode($toolCall['function']['arguments']) ?: (object)[];
					unset($toolCall['function']['arguments']);
					return $toolCall['function'];
				}, $choice['message']['tool_calls']);

				$toolCalls = json_encode($choice['message']['tool_calls']);
				if ($toolCalls === false) {
					$this->logger->debug('Tool calls JSON encoding error: ' . json_last_error_msg());
				} else {
					$completions['tool_calls'][] = $toolCalls;
				}
			}

			// always try to get a message
			if (isset($choice['message']['content']) && is_string($choice['message']['content'])) {
				$completions['messages'][] = $choice['message']['content'];
			}
		}

		return $completions;
	}

	/**
	 * @param string $configKey
	 * @return array|null
	 */
	private function getAdminExtraParams(string $configKey): ?array {
		$stringValue = $this->appConfig->getValueString(Application::APP_ID, $configKey);
		if ($stringValue === '') {
			return null;
		}
		$arrayValue = json_decode($stringValue, true);
		if (!is_array($arrayValue)) {
			return null;
		}
		return $arrayValue;
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
		string $model = Application::DEFAULT_MODEL_ID,
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
		string $model = Application::DEFAULT_MODEL_ID,
	): string {
		try {
			$transcriptionResponse = $this->transcribe($userId, $file->getContent(), $translate, $model);
		} catch (NotPermittedException|LockedException|GenericFileException $e) {
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
		string $model = Application::DEFAULT_MODEL_ID,
	): string {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TRANSCRIPTION)) {
			throw new Exception($this->l10n->t('Audio transcription quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}
		// enforce whisper for OpenAI
		if ($this->isUsingOpenAi()) {
			$model = Application::DEFAULT_TRANSCRIPTION_MODEL_ID;
		}

		$params = [
			'model' => $model === Application::DEFAULT_MODEL_ID ? Application::DEFAULT_TRANSCRIPTION_MODEL_ID : $model,
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
	 * @param string $model
	 * @param int $n
	 * @param string $size
	 * @return array
	 * @throws Exception
	 */
	public function requestImageCreation(
		?string $userId, string $prompt, string $model, int $n = 1, string $size = Application::DEFAULT_DEFAULT_IMAGE_SIZE,
	): array {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_IMAGE)) {
			throw new Exception($this->l10n->t('Image generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$params = [
			'prompt' => $prompt,
			'size' => $size,
			'n' => $n,
			'model' => $model === Application::DEFAULT_MODEL_ID ? Application::DEFAULT_IMAGE_MODEL_ID : $model,
		];

		$apiResponse = $this->request($userId, 'images/generations', $params, 'POST');

		if (!isset($apiResponse['data']) || !is_array($apiResponse['data'])) {
			$this->logger->warning('OpenAI image generation error', ['api_response' => $apiResponse]);
			throw new Exception($this->l10n->t('Unknown image generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);

		} else {
			try {
				$this->quotaUsageMapper->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_IMAGE, $n);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_IMAGE . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		return $apiResponse;
	}

	/**
	 * @param string|null $userId
	 * @return array
	 */
	public function getImageRequestOptions(?string $userId): array {
		$timeout = $this->openAiSettingsService->getRequestTimeout();
		$requestOptions = [
			'timeout' => $timeout,
			'headers' => [
				'User-Agent' => Application::USER_AGENT,
			],
		];

		if ($this->openAiSettingsService->getIsImageRetrievalAuthenticated()) {
			$useBasicAuth = $this->openAiSettingsService->getUseBasicAuth();
			if ($useBasicAuth) {
				$basicUser = $this->openAiSettingsService->getUserBasicUser($userId, true);
				$basicPassword = $this->openAiSettingsService->getUserBasicPassword($userId, true);
				if ($basicUser !== '' && $basicPassword !== '') {
					$requestOptions['headers']['Authorization'] = 'Basic ' . base64_encode($basicUser . ':' . $basicPassword);
				}
			} else {
				$apiKey = $this->openAiSettingsService->getUserApiKey($userId, true);
				$requestOptions['headers']['Authorization'] = 'Bearer ' . $apiKey;
			}
		}
		return $requestOptions;
	}

	/**
	 * @param string|null $userId
	 * @param string $prompt
	 * @param string $model
	 * @param string $voice
	 * @param int $speed
	 * @return array
	 * @throws Exception
	 */
	public function requestSpeechCreation(
		?string $userId, string $prompt, string $model, string $voice, float $speed = 1,
	): array {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_SPEECH)) {
			throw new Exception($this->l10n->t('Speech generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$params = [
			'input' => $prompt,
			'voice' => $voice === Application::DEFAULT_MODEL_ID ? Application::DEFAULT_SPEECH_VOICE : $voice,
			'model' => $model === Application::DEFAULT_MODEL_ID ? Application::DEFAULT_SPEECH_MODEL_ID : $model,
			'response_format' => 'mp3',
			'speed' => $speed,
		];

		$apiResponse = $this->request($userId, 'audio/speech', $params, 'POST');

		try {
			$charCount = mb_strlen($prompt);
			$this->quotaUsageMapper->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_SPEECH, $charCount);
		} catch (DBException $e) {
			$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_SPEECH . '. Error: ' . $e->getMessage());
		}
		return $apiResponse;
	}

	/**
	 * @return int
	 */
	public function getExpTextProcessingTime(): int {
		return $this->isUsingOpenAi()
			? intval($this->appConfig->getValueString(Application::APP_ID, 'openai_text_generation_time', strval(Application::DEFAULT_OPENAI_TEXT_GENERATION_TIME)))
			: intval($this->appConfig->getValueString(Application::APP_ID, 'localai_text_generation_time', strval(Application::DEFAULT_LOCALAI_TEXT_GENERATION_TIME)));
	}

	/**
	 * @param int $runtime
	 * @return void
	 */
	public function updateExpTextProcessingTime(int $runtime): void {
		$oldTime = floatval($this->getExpImgProcessingTime());
		$newTime = (1.0 - Application::EXPECTED_RUNTIME_LOWPASS_FACTOR) * $oldTime + Application::EXPECTED_RUNTIME_LOWPASS_FACTOR * floatval($runtime);

		if ($this->isUsingOpenAi()) {
			$this->appConfig->setValueString(Application::APP_ID, 'openai_text_generation_time', strval(intval($newTime)));
		} else {
			$this->appConfig->setValueString(Application::APP_ID, 'localai_text_generation_time', strval(intval($newTime)));
		}
	}

	/**
	 * @return int
	 */
	public function getExpImgProcessingTime(): int {
		return $this->isUsingOpenAi()
			? intval($this->appConfig->getValueString(Application::APP_ID, 'openai_image_generation_time', strval(Application::DEFAULT_OPENAI_IMAGE_GENERATION_TIME)))
			: intval($this->appConfig->getValueString(Application::APP_ID, 'localai_image_generation_time', strval(Application::DEFAULT_LOCALAI_IMAGE_GENERATION_TIME)));
	}

	/**
	 * @param int $runtime
	 * @return void
	 */
	public function updateExpImgProcessingTime(int $runtime): void {
		$oldTime = floatval($this->getExpImgProcessingTime());
		$newTime = (1.0 - Application::EXPECTED_RUNTIME_LOWPASS_FACTOR) * $oldTime + Application::EXPECTED_RUNTIME_LOWPASS_FACTOR * floatval($runtime);

		if ($this->isUsingOpenAi()) {
			$this->appConfig->setValueString(Application::APP_ID, 'openai_image_generation_time', strval(intval($newTime)));
		} else {
			$this->appConfig->setValueString(Application::APP_ID, 'localai_image_generation_time', strval(intval($newTime)));
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

			$url = rtrim($serviceUrl, '/') . '/' . $endPoint;
			$options = [
				'timeout' => $timeout,
				'headers' => [
					'User-Agent' => Application::USER_AGENT,
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
			} else {
				if ($basicUser !== '' && $basicPassword !== '') {
					$options['headers']['Authorization'] = 'Basic ' . base64_encode($basicUser . ':' . $basicPassword);
				}
			}

			if (!$this->isUsingOpenAi()) {
				$options['nextcloud']['allow_local_address'] = true;
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
			}
			if ($response->getHeader('Content-Type') === 'application/json') {
				$parsedBody = json_decode($body, true);
				if ($parsedBody === null) {
					$this->logger->warning('Could not JSON parse the response', ['body' => $body]);
					return ['error' => 'Could not JSON parse the response'];
				}
				return $parsedBody;
			}
			return ['body' => $body];
		} catch (ClientException|ServerException $e) {
			$responseBody = $e->getResponse()->getBody();
			$parsedResponseBody = json_decode($responseBody, true);
			if ($e->getResponse()->getStatusCode() === 404) {
				$this->logger->debug('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			} else {
				$this->logger->warning('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			}
			throw new Exception(
				$this->l10n->t('API request error: ') . (
					$e->getResponse()->getStatusCode() === 401
						? $this->l10n->t('Invalid API Key/Basic Auth: ')
						: ''
				) . (
					isset($parsedResponseBody['error']) && isset($parsedResponseBody['error']['message'])
						? $parsedResponseBody['error']['message']
						: $e->getMessage()
				),
				intval($e->getCode()),
			);
		}
	}
}
