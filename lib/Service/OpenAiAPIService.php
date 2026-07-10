<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use DateTime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Utils;
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
use OCP\Notification\IManager as INotificationManager;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
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
	private array $modelsMemoryCache = [];

	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IAppConfig $appConfig,
		private ICacheFactory $cacheFactory,
		private QuotaUsageMapper $quotaUsageMapper,
		private OpenAiSettingsService $openAiSettingsService,
		private StreamingService $streamingService,
		private OpenAiFileService $openAiFileService,
		private INotificationManager $notificationManager,
		private QuotaRuleService $quotaRuleService,
		IClientService $clientService,
		private bool $isCLI,
	) {
		// chooseHandler returns a wrapper that chooses the StreamHandler if the request 'stream' option is set
		/** @psalm-suppress TooManyArguments */
		$this->client = $clientService->newClient(Utils::chooseHandler());
	}

	/**
	 * @param string $userId It can be an empty string
	 * @param int $type
	 * @param int $usage
	 * @throws Exception If there is an error creating the quota usage.
	 */
	public function createQuotaUsage(string $userId, int $type, int $usage) {
		$rule = $this->quotaRuleService->getRule($type, $userId);
		$this->quotaUsageMapper->createQuotaUsage($userId, $type, $usage, $rule['pool'] ? $rule['id'] : -1);
	}

	/**
	 * @param ?string $serviceType
	 * @return bool
	 */
	public function isUsingOpenAi(?string $serviceType = null): bool {
		$serviceUrl = '';
		if ($serviceType === Application::SERVICE_TYPE_IMAGE) {
			$serviceUrl = $this->openAiSettingsService->getImageServiceUrl();
		} elseif ($serviceType === Application::SERVICE_TYPE_STT) {
			$serviceUrl = $this->openAiSettingsService->getSttServiceUrl();
		} elseif ($serviceType === Application::SERVICE_TYPE_TTS) {
			$serviceUrl = $this->openAiSettingsService->getTtsServiceUrl();
		}
		if ($serviceUrl === '') {
			$serviceUrl = $this->openAiSettingsService->getServiceUrl();
		}
		return $serviceUrl === '' || $serviceUrl === Application::OPENAI_API_BASE_URL;
	}

	/**
	 * @param ?string $serviceType
	 *
	 * @return string
	 */
	public function getServiceName(?string $serviceType = null): string {
		if ($this->isUsingOpenAi($serviceType)) {
			if ($serviceType === Application::SERVICE_TYPE_IMAGE) {
				return $this->l10n->t('OpenAI\'s Image Generation');
			}
			if ($serviceType === Application::SERVICE_TYPE_TTS) {
				$this->l10n->t('OpenAI\'s Text to Speech');
			}
			return 'OpenAI';
		} else {
			$serviceName = $this->openAiSettingsService->getServiceName();
			if ($serviceType === Application::SERVICE_TYPE_IMAGE && $this->openAiSettingsService->imageOverrideEnabled()) {
				$serviceName = $this->openAiSettingsService->getImageServiceName();
			} elseif ($serviceType === Application::SERVICE_TYPE_STT && $this->openAiSettingsService->sttOverrideEnabled()) {
				$serviceName = $this->openAiSettingsService->getSttServiceName();
			} elseif ($serviceType === Application::SERVICE_TYPE_TTS && $this->openAiSettingsService->ttsOverrideEnabled()) {
				$serviceName = $this->openAiSettingsService->getTtsServiceName();
			}
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
	 * @param ?string $userId
	 * @param bool $refresh
	 * @param ?string $serviceType
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getModels(?string $userId, bool $refresh = false, ?string $serviceType = null): array {
		// Use default service type if service type is not overridden
		if ($serviceType === Application::SERVICE_TYPE_IMAGE && !$this->openAiSettingsService->imageOverrideEnabled()) {
			$serviceType = null;
		} elseif ($serviceType === Application::SERVICE_TYPE_STT && !$this->openAiSettingsService->sttOverrideEnabled()) {
			$serviceType = null;
		} elseif ($serviceType === Application::SERVICE_TYPE_TTS && !$this->openAiSettingsService->ttsOverrideEnabled()) {
			$serviceType = null;
		}
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		$userCacheKey = Application::MODELS_CACHE_KEY . '_' . ($userId ?? '') . '_' . ($serviceType ?? 'main');
		$adminCacheKey = Application::MODELS_CACHE_KEY . '-main' . '_' . ($serviceType ?? 'main');
		$dbCacheKey = $serviceType ? 'models' . '_' . $serviceType : 'models';
		$memoryCacheKey = $serviceType ?? 'default';

		if (!$refresh) {
			if (array_key_exists($memoryCacheKey, $this->modelsMemoryCache)) {
				$this->logger->debug('Getting OpenAI models from the memory cache');
				return $this->modelsMemoryCache[$memoryCacheKey];
			}

			// try to get models from the user cache first
			if ($userId !== null) {
				$userCachedModels = $cache->get($userCacheKey);
				if ($userCachedModels) {
					$this->logger->debug('Getting OpenAI models from user cache for user ' . $userId);
					$this->modelsMemoryCache[$memoryCacheKey] = $userCachedModels;
					return $userCachedModels;
				}
			}

			// if the user has an API key or uses basic auth, skip the admin cache
			if ($userId === null || (
				$this->openAiSettingsService->getUserApiKey($userId, false) === ''
				&& (
					!$this->openAiSettingsService->getUseBasicAuth()
					|| $this->openAiSettingsService->getUserBasicUser($userId) === ''
					|| $this->openAiSettingsService->getUserBasicPassword($userId) === ''
				)
			)) {
				// here we know there is either no user cache or userId is null
				// so if there is no user-defined service credentials
				// we try to get the models from the admin cache
				if ($adminCachedModels = $cache->get($adminCacheKey)) {
					$this->logger->debug('Getting OpenAI models from the main distributed cache');
					$this->modelsMemoryCache[$memoryCacheKey] = $adminCachedModels;
					return $adminCachedModels;
				}
			}

			// if we don't need to refresh to model list and it's not been found in the cache, it is obtained from the DB
			$modelsObjectString = $this->appConfig->getValueString(Application::APP_ID, $dbCacheKey, '{"data":[],"object":"list"}');
			$fallbackModels = [
				'data' => [],
				'object' => 'list',
			];
			try {
				$newCache = json_decode($modelsObjectString, true) ?? $fallbackModels;
			} catch (Throwable $e) {
				$this->logger->warning('Could not decode the model JSON string', ['model_string', $modelsObjectString, 'exception' => $e]);
				$newCache = $fallbackModels;
			}
			$cache->set($userId !== null ? $userCacheKey : $adminCacheKey, $newCache, Application::MODELS_CACHE_TTL);
			$this->modelsMemoryCache[$memoryCacheKey] = $newCache;
			return $newCache;
		}

		// we know we are refreshing so we clear the caches and make the network request
		$cache->remove($adminCacheKey);
		$cache->remove($userCacheKey);

		try {
			$this->logger->debug('Actually getting OpenAI models with a network request');
			$modelsResponse = $this->request($userId, 'models', serviceType: $serviceType);
		} catch (Exception $e) {
			$this->logger->warning('Error retrieving models (exc): ' . $e->getMessage());
			throw $e;
		}
		if (isset($modelsResponse['error'])) {
			$this->logger->warning('Error retrieving models: ' . json_encode($modelsResponse));
			throw new Exception($modelsResponse['error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if (!isset($modelsResponse['data'])) {
			// also consider responses without 'data' as valid
			$modelsResponse = ['data' => $modelsResponse];
		}

		if (!$this->isModelListValid($modelsResponse['data'])) {
			$this->logger->warning('Invalid models response: ' . json_encode($modelsResponse));
			throw new Exception($this->l10n->t('Invalid models response received'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$cache->set($userId !== null ? $userCacheKey : $adminCacheKey, $modelsResponse, Application::MODELS_CACHE_TTL);
		$this->modelsMemoryCache[$memoryCacheKey] = $modelsResponse;
		// we always store the model list after getting it
		$modelsObjectString = json_encode($modelsResponse);
		$this->appConfig->setValueString(Application::APP_ID, $dbCacheKey, $modelsObjectString);
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
	public function getModelEnumValues(?string $userId, ?string $serviceType = null): array {
		try {
			$modelResponse = $this->getModels($userId, false, $serviceType);
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
		$rule = $this->quotaRuleService->getRule($type, $userId);
		$quota = $rule['amount'];
		$pool = $rule['pool'] ? $rule['id'] : null;

		if ($quota === 0) {
			//  Unlimited quota:
			return false;
		}

		$quotaStart = $this->openAiSettingsService->getQuotaStart();

		try {
			$quotaUsage = $this->quotaUsageMapper->getQuotaUnitsOfUserInTimePeriod($userId, $type, $quotaStart, $pool);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|DBException|RuntimeException $e) {
			$this->logger->warning('Could not retrieve quota usage for user: ' . $userId . ' and quota type: ' . $type . '. Error: ' . $e->getMessage());
			throw new Exception('Could not retrieve quota usage.', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if ($quotaUsage < $quota) {
			return false;
		}
		$cache = $this->cacheFactory->createLocal(Application::APP_ID);
		if ($cache->get('quota_exceeded_' . $userId . '_' . $type) === null) {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new DateTime())
				->setObject('quota_exceeded', (string)$type)
				->setSubject('quota_exceeded', ['type' => $type]);
			$this->notificationManager->notify($notification);
			$cache->set('quota_exceeded_' . $userId . '_' . $type, true, 3600);
		}
		return true;
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
		$ownApikey = $this->hasOwnOpenAiApiKey($userId);
		// Get quota period
		$quotaPeriod = $this->openAiSettingsService->getQuotaPeriod();
		$quotaStart = $this->openAiSettingsService->getQuotaStart();
		$quotaEnd = $this->openAiSettingsService->getQuotaEnd();
		// Get quota usage for each quota type:
		$quotaInfo = [];
		foreach (Application::DEFAULT_QUOTAS as $quotaType => $_) {
			$quotaInfo[$quotaType]['type'] = $this->translatedQuotaType($quotaType);
			try {
				$quotaInfo[$quotaType]['used'] = $this->quotaUsageMapper->getQuotaUnitsOfUserInTimePeriod($userId, $quotaType, $quotaStart);
			} catch (DoesNotExistException|MultipleObjectsReturnedException|DBException|RuntimeException $e) {
				$this->logger->warning('Could not retrieve quota usage for user: ' . $userId . ' and quota type: ' . $quotaType . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				throw new Exception($this->l10n->t('Unknown error while retrieving quota usage.'), Http::STATUS_INTERNAL_SERVER_ERROR);
			}
			if ($ownApikey) {
				$quotaInfo[$quotaType]['limit'] = Application::DEFAULT_QUOTAS[$quotaType];
			} else {
				$rule = $this->quotaRuleService->getRule($quotaType, $userId);
				$quotaInfo[$quotaType]['limit'] = $rule['amount'];
				if ($rule['pool']) {
					$quotaInfo[$quotaType]['used_pool'] = $this->quotaUsageMapper->getQuotaUnitsOfUserInTimePeriod($userId, $quotaType, $quotaStart, $rule['id']);
				}
			}
			$quotaInfo[$quotaType]['unit'] = $this->translatedQuotaUnit($quotaType);
		}

		return [
			'quota_usage' => $quotaInfo,
			'period' => $quotaPeriod,
			'start' => $quotaStart,
			'end' => $quotaEnd,
		];
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	public function getAdminQuotaInfo(): array {
		// Get quota start time
		$startTime = $this->openAiSettingsService->getQuotaStart();
		// Get quota usage of all users for each quota type:
		$quotaInfo = [];
		foreach (Application::DEFAULT_QUOTAS as $quotaType => $_) {
			$quotaInfo[$quotaType]['type'] = $this->translatedQuotaType($quotaType);
			try {
				$quotaInfo[$quotaType]['used'] = $this->quotaUsageMapper->getQuotaUnitsInTimePeriod($quotaType, $startTime);
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
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage);
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

	public function createStreamedChatCompletion(
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
		?array $files = null,
	): \Generator {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TEXT)) {
			throw new Exception($this->l10n->t('Text generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$params = $this->buildChatCompletionRequestParams(
			$userId,
			$model,
			$userPrompt,
			$systemPrompt,
			$history,
			$n,
			$maxTokens,
			$extraParams,
			$toolMessage,
			$tools,
			$files,
			true,
		);

		$response = $this->request(
			$userId,
			'chat/completions',
			$params,
			'POST',
			null,
			true,
			null,
			0,
			true,
		);

		$streamResult = yield from $this->streamingService->parseStreamChatResponse($response);

		if (isset($streamResult['usage']['total_tokens'])) {
			$usage = $streamResult['usage']['total_tokens'];
			try {
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TEXT . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}

		return $this->normalizeChatCompletionResponse($streamResult);
	}

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
		?array $files = null,
	): array {
		$response = $this->requestChatCompletion(
			$userId, $model, $userPrompt, $systemPrompt, $history,
			$n, $maxTokens, $extraParams, $toolMessage, $tools, $files,
			false,
		);

		if (isset($response['usage'], $response['usage']['total_tokens'])) {
			$usage = $response['usage']['total_tokens'];
			try {
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TEXT . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		return $this->normalizeChatCompletionResponse($response);
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
	 * @param array|null $files Array of File objects
	 * @return array{messages?: array<string>, tool_calls?: array<string>, audio_messages?: list<array<string, mixed>>, usage?: array<string, mixed>}
	 * @throws Exception
	 */
	public function requestChatCompletion(
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
		?array $files = null,
		bool $stream = false,
	): array {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TEXT)) {
			throw new Exception($this->l10n->t('Text generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$params = $this->buildChatCompletionRequestParams(
			$userId,
			$model,
			$userPrompt,
			$systemPrompt,
			$history,
			$n,
			$maxTokens,
			$extraParams,
			$toolMessage,
			$tools,
			$files,
			$stream,
		);

		return $this->request($userId, 'chat/completions', $params, 'POST');
	}

	/**
	 * @param string|null $userId
	 * @param string $model
	 * @param string|null $userPrompt
	 * @param string|null $systemPrompt
	 * @param array|null $history
	 * @param int $n
	 * @param int|null $maxTokens
	 * @param array|null $extraParams
	 * @param string|null $toolMessage
	 * @param array|null $tools
	 * @param array|null $files Array of File objects
	 * @param bool $stream
	 * @return array<string, mixed>
	 */
	private function buildChatCompletionRequestParams(
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
		?array $files = null,
		bool $stream = false,
	): array {
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
			foreach ($history as $historyEntry) {
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
						if ($formattedToolCall['function']['arguments'] === '[]') {
							$formattedToolCall['function']['arguments'] = '{}';
						}
						unset($formattedToolCall['function']['id']);
						unset($formattedToolCall['function']['args']);
						unset($formattedToolCall['function']['type']);
						return $formattedToolCall;
					}, $message['tool_calls']);
				}
				$messages[] = $message;
			}
		}
		// Attach all files when necessary
		if ($files !== null) {
			$content = $this->openAiFileService->buildFileContents($files);
			if ($userPrompt !== null) {
				$content[] = [
					'type' => 'text',
					'text' => $userPrompt,
				];
			}
			$messages[] = [
				'role' => 'user',
				'content' => $content,
			];
		} elseif ($userPrompt !== null) {
			$messages[] = [
				'role' => 'user',
				'content' => $userPrompt,
			];
		}
		if ($toolMessage !== null) {
			$msgs = json_decode($toolMessage, true);
			foreach ($msgs as $msg) {
				$msg['role'] = 'tool';
				if (!is_string($msg['content'])) {
					// IONOS requires tool contents to be strings
					$msg['content'] = json_encode($msg['content']);
				}
				$messages[] = $msg;
			}
		}

		$params = [
			'model' => $modelRequestParam,
			'messages' => $messages,
			'n' => $n,
			'stream' => $stream,
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
		if ($stream && $this->isUsingOpenAi()) {
			$params['stream_options'] = array_merge(
				is_array($params['stream_options'] ?? null) ? $params['stream_options'] : [],
				['include_usage' => true],
			);
		}

		return $params;
	}

	/**
	 * @param string $configKey
	 * @return array|null
	 */
	private function getAdminExtraParams(string $configKey): ?array {
		$stringValue = $this->appConfig->getValueString(Application::APP_ID, $configKey, lazy: true);
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
	 * @param string $model
	 * @param string $language
	 * @param string $responseFormat
	 * @return string
	 * @throws Exception
	 */
	public function transcribeFile(
		?string $userId,
		File $file,
		bool $translate = false,
		string $model = Application::DEFAULT_MODEL_ID,
		string $language = 'default',
		string $responseFormat = 'verbose_json',
	): string {
		try {
			$transcriptionResponse = $this->transcribe($userId, $file->getContent(), $translate, $model, $language, $responseFormat);
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
	 * @param string $language
	 * @param string $responseFormat
	 * @return string
	 * @throws Exception
	 */
	public function transcribe(
		?string $userId,
		string $audioFileContent,
		bool $translate = true,
		string $model = Application::DEFAULT_MODEL_ID,
		string $language = 'default',
		string $responseFormat = 'verbose_json', // Verbose needed for extraction of audio duration
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
			'response_format' => $responseFormat,
		];
		// Gets the user's preferred language if it's not the default one
		if ($language === 'default') {
			$language = $this->openAiSettingsService->getUserSTTLanguage($userId);
		}
		if ($language !== 'detect_language') {
			$params['language'] = $language;
		}
		$endpoint = $translate ? 'audio/translations' : 'audio/transcriptions';
		$contentType = 'multipart/form-data';

		$response = $this->request($userId, $endpoint, $params, 'POST', $contentType, serviceType: Application::SERVICE_TYPE_STT);

		if (in_array($responseFormat, Application::SUPPORTED_SUBTITLE_FORMATS)) {
			if (!isset($response['body'])) {
				$this->logger->warning('Audio subtitling error: ' . json_encode($response));
				throw new Exception($this->l10n->t('Unknown audio subtitling error'), Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			// Extract audio duration from response and store it as quota usage:
			$matches = [];
			$isMatch = preg_match_all('/(\d\d):(\d\d):(\d\d)[\.,](\d\d\d)/', $response['body'], $matches, PREG_SET_ORDER);

			if ($isMatch !== false && $isMatch > 0) {
				$lastTimestamp = end($matches);
				$hours = intval($lastTimestamp[1]);
				$minutes = intval($lastTimestamp[2]);
				$seconds = intval($lastTimestamp[3]);
				$millisecondAdjustment = intval(round(floatval($lastTimestamp[4]) / 1000.0));
				$audioDuration = ($hours * 3600) + ($minutes * 60) + $seconds + $millisecondAdjustment;

				try {
					$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TRANSCRIPTION, $audioDuration);
				} catch (DBException $e) {
					$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TRANSCRIPTION . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
				}
			}

			return $response['body'];
		}

		if (!isset($response['text'])) {
			$this->logger->warning('Audio transcription error: ' . json_encode($response));
			throw new Exception($this->l10n->t('Unknown audio trancription error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// Extract audio duration from response and store it as quota usage:
		if (isset($response['segments'])) {
			$audioDuration = intval(round(floatval(array_pop($response['segments'])['end'])));

			if ($audioDuration < 0) {
				$this->logger->warning('Audio duration is less than 0: ' . $audioDuration);
				$audioDuration = 0;
			}

			// Audio durations higher than this can cause errors in the database: https://github.com/nextcloud/integration_openai/issues/394
			if ($audioDuration > 2147483647) {
				$this->logger->warning('Audio duration is greater than 2147483647 seconds: ' . $audioDuration);
				$audioDuration = 2147483647;
			}

			try {
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TRANSCRIPTION, $audioDuration);
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
		?string $userId,
		string $prompt,
		string $model,
		int $n = 1,
		string $size = Application::DEFAULT_DEFAULT_IMAGE_SIZE,
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

		$apiResponse = $this->request($userId, 'images/generations', $params, 'POST', serviceType: Application::SERVICE_TYPE_IMAGE);

		if (!isset($apiResponse['data']) || !is_array($apiResponse['data'])) {
			$this->logger->warning('OpenAI image generation error', ['api_response' => $apiResponse]);
			throw new Exception($this->l10n->t('Unknown image generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		} else {
			try {
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_IMAGE, $n);
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
		$requestOptions = [
			'timeout' => $this->openAiSettingsService->getRequestTimeout(),
			'headers' => [
				'User-Agent' => Application::USER_AGENT,
			],
		];

		if ($this->openAiSettingsService->getIsImageRetrievalAuthenticated()) {
			if ($this->openAiSettingsService->imageOverrideEnabled()) {
				$useBasicAuth = $this->openAiSettingsService->getAdminImageUseBasicAuth();

				$apiKey = $this->openAiSettingsService->getAdminImageApiKey();
				$basicUser = $this->openAiSettingsService->getAdminImageBasicUser();
				$basicPassword = $this->openAiSettingsService->getAdminImageBasicPassword();

				$requestOptions['timeout'] = $this->openAiSettingsService->getImageRequestTimeout();
			} else {
				// image service settings are not overridden
				$useBasicAuth = $this->openAiSettingsService->getUseBasicAuth();

				// this has no equivalent when the service URL is overridden
				// so the user-defined credentials will be ignored
				$apiKey = $this->openAiSettingsService->getUserApiKey($userId, true);
				$basicUser = $this->openAiSettingsService->getUserBasicUser($userId, true);
				$basicPassword = $this->openAiSettingsService->getUserBasicPassword($userId, true);
			}
			if ($useBasicAuth) {
				if ($basicUser !== '' && $basicPassword !== '') {
					$requestOptions['headers']['Authorization'] = 'Basic ' . base64_encode($basicUser . ':' . $basicPassword);
				}
			} else {
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
	 * @param float $speed
	 * @return array
	 * @throws Exception
	 */
	public function requestSpeechCreation(
		?string $userId,
		string $prompt,
		string $model,
		string $voice,
		float $speed = 1,
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

		$apiResponse = $this->request($userId, 'audio/speech', $params, 'POST', serviceType: Application::SERVICE_TYPE_TTS);

		try {
			$charCount = mb_strlen($prompt);
			$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_SPEECH, $charCount);
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
			? intval($this->appConfig->getValueString(Application::APP_ID, 'openai_text_generation_time', strval(Application::DEFAULT_OPENAI_TEXT_GENERATION_TIME), lazy: true))
			: intval($this->appConfig->getValueString(Application::APP_ID, 'localai_text_generation_time', strval(Application::DEFAULT_LOCALAI_TEXT_GENERATION_TIME), lazy: true));
	}

	/**
	 * @param int $runtime
	 * @return void
	 */
	public function updateExpTextProcessingTime(int $runtime): void {
		$oldTime = floatval($this->getExpTextProcessingTime());
		$newTime = (1.0 - Application::EXPECTED_RUNTIME_LOWPASS_FACTOR) * $oldTime + Application::EXPECTED_RUNTIME_LOWPASS_FACTOR * floatval($runtime);

		if ($this->isUsingOpenAi()) {
			$this->appConfig->setValueString(Application::APP_ID, 'openai_text_generation_time', strval(intval($newTime)), lazy: true);
		} else {
			$this->appConfig->setValueString(Application::APP_ID, 'localai_text_generation_time', strval(intval($newTime)), lazy: true);
		}
	}

	/**
	 * @return int
	 */
	public function getExpImgProcessingTime(): int {
		return $this->isUsingOpenAi(Application::SERVICE_TYPE_IMAGE)
			? intval($this->appConfig->getValueString(Application::APP_ID, 'openai_image_generation_time', strval(Application::DEFAULT_OPENAI_IMAGE_GENERATION_TIME), lazy: true))
			: intval($this->appConfig->getValueString(Application::APP_ID, 'localai_image_generation_time', strval(Application::DEFAULT_LOCALAI_IMAGE_GENERATION_TIME), lazy: true));
	}

	/**
	 * @param int $runtime
	 * @return void
	 */
	public function updateExpImgProcessingTime(int $runtime): void {
		$oldTime = floatval($this->getExpImgProcessingTime());
		$newTime = (1.0 - Application::EXPECTED_RUNTIME_LOWPASS_FACTOR) * $oldTime + Application::EXPECTED_RUNTIME_LOWPASS_FACTOR * floatval($runtime);

		if ($this->isUsingOpenAi(Application::SERVICE_TYPE_IMAGE)) {
			$this->appConfig->setValueString(Application::APP_ID, 'openai_image_generation_time', strval(intval($newTime)), lazy: true);
		} else {
			$this->appConfig->setValueString(Application::APP_ID, 'localai_image_generation_time', strval(intval($newTime)), lazy: true);
		}
	}

	/**
	 * Make an HTTP request to the OpenAI API
	 * @param string|null $userId
	 * @param string $endPoint The path to reach
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @param string|null $contentType
	 * @param bool $logErrors if set to false error logs will be suppressed
	 * @param string|null $serviceType
	 * @param int $retryCount number of retries that have been attempted so far
	 * @return array decoded request result or error
	 * @throws Exception|UserFacingProcessingException
	 */
	public function request(
		?string $userId, string $endPoint, array $params = [], string $method = 'GET',
		?string $contentType = null, bool $logErrors = true, ?string $serviceType = null,
		int $retryCount = 0,
		bool $stream = false,
	): array {
		try {
			$context = $this->getRequestContext($userId, $serviceType);
			$serviceUrl = $context['serviceUrl'];
			$apiKey = $context['apiKey'];
			$basicUser = $context['basicUser'];
			$basicPassword = $context['basicPassword'];
			$useBasicAuth = $context['useBasicAuth'];
			$timeout = $context['timeout'];

			$url = rtrim($serviceUrl, '/') . '/' . $endPoint;
			$options = [
				'timeout' => $timeout,
				'headers' => [
					'User-Agent' => Application::USER_AGENT,
				],
			];

			if ($serviceUrl === Application::OPENAI_API_BASE_URL && $apiKey === '') {
				return ['error' => 'An API key is required for api.openai.com'];
			}

			if ($this->isUsingOpenAi($serviceType) || !$useBasicAuth) {
				if ($apiKey !== '') {
					$options['headers']['Authorization'] = 'Bearer ' . $apiKey;
				}
			} else {
				if ($basicUser !== '' && $basicPassword !== '') {
					$options['headers']['Authorization'] = 'Basic ' . base64_encode($basicUser . ':' . $basicPassword);
				}
			}

			if (!$this->isUsingOpenAi($serviceType)) {
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

			if ($stream) {
				$options['headers']['Accept'] = 'text/event-stream';
				$options['stream'] = true;
				// Guzzle's StreamHandler only supports HTTP/1.x, so streamed
				// responses must not force the default HTTP/2 transport settings.
				$options['version'] = '1.1';
				$options['curl'] = [];
				$options['curl'][\CURLOPT_HTTP_VERSION] = \CURL_HTTP_VERSION_1_1;
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
			$contentTypeHeader = $response->getHeader('Content-Type');

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			}
			if (str_starts_with(strtolower($contentTypeHeader), 'application/json')) {
				if (is_resource($body)) {
					$body = stream_get_contents($body);
				}
				if (!is_string($body)) {
					$this->logger->warning('Could not read the JSON response body', ['body_type' => gettype($body)]);
					return ['error' => 'Could not read the JSON response body'];
				}
				$parsedBody = json_decode($body, true);
				if ($parsedBody === null) {
					$this->logger->warning('Could not JSON parse the response', ['body' => $body]);
					return ['error' => 'Could not JSON parse the response'];
				}
				if ($stream) {
					return [
						'body' => $parsedBody,
						'content-type' => $contentTypeHeader,
					];
				}
				return $parsedBody;
			}
			return [
				'body' => $body,
				'content-type' => $contentTypeHeader,
			];
		} catch (ClientException|ServerException $e) {
			if ($e->getResponse()->getStatusCode() === Http::STATUS_TOO_MANY_REQUESTS) {
				if ($retryCount < 3 && $this->isCLI) {
					if (empty($e->getResponse()->getHeader('Retry-After'))) {
						$sleep = random_int(10, 120);
					} else {
						$retryAfter = $e->getResponse()->getHeader('Retry-After')[0];
						if ((string)(int)$retryAfter !== $retryAfter) {
							// if it's not an integer, it might be a date
							$retryAfterTime = strtotime($retryAfter);
							if ($retryAfterTime !== false) {
								$sleep = max(0, $retryAfterTime - time());
							} else {
								// fallback to random sleep if the header is not parsable
								$sleep = random_int(10, 120);
							}
						} else {
							$sleep = (int)$retryAfter;
						}
						$sleep += random_int(5, 30); // add some jitter to avoid thundering herd problem
					}
					$this->logger->warning("Rate limit exceeded, retrying in $sleep seconds", ['retry_count' => $retryCount]);
					sleep($sleep);
					return $this->request($userId, $endPoint, $params, $method, $contentType, $logErrors, $serviceType, $retryCount + 1, $stream);
				} else {
					$this->logger->warning('Rate limit exceeded, maximum retries reached', ['retry_count' => $retryCount]);
				}
			}
			$responseBody = $e->getResponse()->getBody();
			$parsedResponseBody = json_decode($responseBody, true);
			if ($logErrors) {
				if ($e->getResponse()->getStatusCode() === 404) {
					$this->logger->debug('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
				} else {
					$this->logger->warning('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
				}
			}
			$errorMessage = (
				$e->getResponse()->getStatusCode() === 401
					? $this->l10n->t('Invalid API Key/Basic Auth: ')
					: ''
			) . (
				isset($parsedResponseBody['error']) && isset($parsedResponseBody['error']['message'])
					? $parsedResponseBody['error']['message']
					: $e->getMessage()
			);
			if ($e->getResponse()->getStatusCode() == 401 && $e->getResponse()->getStatusCode() < 500) {
				throw new UserFacingProcessingException(
					$this->l10n->t('API request error: ') . $errorMessage,
					intval($e->getCode()),
					userFacingMessage: $this->l10n->t('%s API error: Invalid API key or invalid Basic Authentication. Contact your system administrator.', [$this->getServiceName()]),
				);
			}
			if ($e->getResponse()->getStatusCode() >= 500) {
				throw new UserFacingProcessingException(
					$this->l10n->t('API request error: ') . $errorMessage,
					intval($e->getCode()),
					userFacingMessage: $this->l10n->t('%s API error: AI backend is currently not available. Contact your system administrator.', [$this->getServiceName()]),
				);
			}
			throw new Exception(
				$this->l10n->t('API request error: ') . $errorMessage,
				intval($e->getCode()),
			);
		} catch (ConnectException $e) {
			if ($logErrors) {
				$this->logger->warning('API connection error: ' . $e->getMessage(), ['exception' => $e]);
			}
			throw new UserFacingProcessingException(
				$this->l10n->t('API connection error: ') . $e->getMessage(),
				intval($e->getCode()),
				userFacingMessage: $this->l10n->t('%s API error: AI backend is currently not reachable. Contact your system administrator.', [$this->getServiceName()]),
			);
		}
	}

	/**
	 * @param string|null $userId
	 * @param string|null $serviceType
	 * @return array{serviceUrl: string, apiKey: string, basicUser: string, basicPassword: string, useBasicAuth: bool, timeout: int}
	 */
	private function getRequestContext(?string $userId, ?string $serviceType = null): array {
		if ($serviceType === Application::SERVICE_TYPE_IMAGE && $this->openAiSettingsService->imageOverrideEnabled()) {
			return [
				'serviceUrl' => $this->openAiSettingsService->getImageServiceUrl(),
				'apiKey' => $this->openAiSettingsService->getAdminImageApiKey(),
				'basicUser' => $this->openAiSettingsService->getAdminImageBasicUser(),
				'basicPassword' => $this->openAiSettingsService->getAdminImageBasicPassword(),
				'useBasicAuth' => $this->openAiSettingsService->getAdminImageUseBasicAuth(),
				'timeout' => $this->openAiSettingsService->getImageRequestTimeout(),
			];
		}
		if ($serviceType === Application::SERVICE_TYPE_STT && $this->openAiSettingsService->sttOverrideEnabled()) {
			return [
				'serviceUrl' => $this->openAiSettingsService->getSttServiceUrl(),
				'apiKey' => $this->openAiSettingsService->getAdminSttApiKey(),
				'basicUser' => $this->openAiSettingsService->getAdminSttBasicUser(),
				'basicPassword' => $this->openAiSettingsService->getAdminSttBasicPassword(),
				'useBasicAuth' => $this->openAiSettingsService->getAdminSttUseBasicAuth(),
				'timeout' => $this->openAiSettingsService->getSttRequestTimeout(),
			];
		}
		if ($serviceType === Application::SERVICE_TYPE_TTS && $this->openAiSettingsService->ttsOverrideEnabled()) {
			return [
				'serviceUrl' => $this->openAiSettingsService->getTtsServiceUrl(),
				'apiKey' => $this->openAiSettingsService->getAdminTtsApiKey(),
				'basicUser' => $this->openAiSettingsService->getAdminTtsBasicUser(),
				'basicPassword' => $this->openAiSettingsService->getAdminTtsBasicPassword(),
				'useBasicAuth' => $this->openAiSettingsService->getAdminTtsUseBasicAuth(),
				'timeout' => $this->openAiSettingsService->getTtsRequestTimeout(),
			];
		}

		$serviceUrl = $this->openAiSettingsService->getServiceUrl();
		if ($serviceUrl === '') {
			$serviceUrl = Application::OPENAI_API_BASE_URL;
		}

		return [
			'serviceUrl' => $serviceUrl,
			'apiKey' => $this->openAiSettingsService->getUserApiKey($userId, true),
			'basicUser' => $this->openAiSettingsService->getUserBasicUser($userId, true),
			'basicPassword' => $this->openAiSettingsService->getUserBasicPassword($userId, true),
			'useBasicAuth' => $this->openAiSettingsService->getUseBasicAuth(),
			'timeout' => $this->openAiSettingsService->getRequestTimeout(),
		];
	}

	/**
	 * @param array<string, mixed> $response
	 * @return array{messages: array<string>, reasoning_messages: array<string>, tool_calls: array<string>, audio_messages: list<array<string, mixed>>}
	 * @throws Exception
	 */
	private function normalizeChatCompletionResponse(array $response): array {
		if (!isset($response['choices']) || !is_array($response['choices'])) {
			$this->logger->warning('Text generation error: ' . json_encode($response));
			throw new Exception($this->l10n->t('Unknown text generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$completions = [
			'messages' => [],
			'reasoning_messages' => [],
			'tool_calls' => [],
			'audio_messages' => [],
			'images' => [],
		];


		foreach ($response['choices'] as $choice) {
			if (!is_array($choice)) {
				continue;
			}

			if (
				($choice['finish_reason'] ?? null) === 'tool_calls'
				&& isset($choice['message']['tool_calls'])
				&& is_array($choice['message']['tool_calls'])
			) {
				$formattedToolCalls = array_map(static function ($toolCall) {
					if (!is_array($toolCall) || !isset($toolCall['function']) || !is_array($toolCall['function'])) {
						return null;
					}
					$function = $toolCall['function'];
					$function['id'] = $toolCall['id'] ?? ($function['id'] ?? '');
					$function['args'] = json_decode($function['arguments'] ?? '{}') ?: (object)[];
					unset($function['arguments']);
					return $function;
				}, $choice['message']['tool_calls']);
				$formattedToolCalls = array_values(array_filter($formattedToolCalls, static fn ($toolCall) => is_array($toolCall)));

				$toolCalls = json_encode($formattedToolCalls);
				if ($toolCalls === false) {
					$this->logger->debug('Tool calls JSON encoding error: ' . json_last_error_msg());
				} else {
					$completions['tool_calls'][] = $toolCalls;
				}
			}

			if (isset($choice['message']['content']) && is_string($choice['message']['content'])) {
				$completions['messages'][] = $choice['message']['content'];
			}
			if (isset($choice['message']['reasoning_content']) && is_string($choice['message']['reasoning_content'])) {
				$completions['reasoning_messages'][] = $choice['message']['reasoning_content'];
			}
			if (isset($choice['message']['audio'], $choice['message']['audio']['data']) && is_string($choice['message']['audio']['data'])) {
				$completions['audio_messages'][] = $choice['message'];
			}
			if (isset($choice['message']['images']) && is_array($choice['message']['images'])) {
				foreach ($choice['message']['images'] as $image) {
					$completions['images'][] = $image;
				}
			}
		}

		return $completions;
	}

	/**
	 * Check if the T2I provider is available
	 *
	 * @return bool whether the T2I provider is available
	 */
	public function isT2IAvailable(): bool {
		if ($this->openAiSettingsService->imageOverrideEnabled() || $this->isUsingOpenAi()) {
			return true;
		}
		try {
			$params = [
				'prompt' => 'a',
				'model' => 'invalid-model',
			];
			$this->request(null, 'images/generations', $params, 'POST', logErrors: false, serviceType: Application::SERVICE_TYPE_IMAGE);
		} catch (Exception $e) {
			return $e->getCode() !== Http::STATUS_NOT_FOUND && $e->getCode() !== Http::STATUS_UNAUTHORIZED;
		}
		return true;
	}

	/**
	 * Check if the STT provider is available
	 *
	 * @return bool whether the STT provider is available
	 */
	public function isSTTAvailable(): bool {
		if ($this->openAiSettingsService->sttOverrideEnabled() || $this->isUsingOpenAi()) {
			return true;
		}
		try {
			$params = [
				'model' => 'invalid-model',
				'file' => 'a',
			];
			$this->request(null, 'audio/translations', $params, 'POST', 'multipart/form-data', logErrors: false, serviceType: Application::SERVICE_TYPE_STT);
		} catch (Exception $e) {
			return $e->getCode() !== Http::STATUS_NOT_FOUND && $e->getCode() !== Http::STATUS_UNAUTHORIZED;
		}
		return true;
	}

	/**
	 * Check if the TTS provider is available
	 *
	 * @return bool whether the TTS provider is available
	 */
	public function isTTSAvailable(): bool {
		if ($this->openAiSettingsService->ttsOverrideEnabled() || $this->isUsingOpenAi()) {
			return true;
		}
		try {
			$params = [
				'input' => 'a',
				'voice' => 'invalid-voice',
				'model' => 'invalid-model',
				'response_format' => 'mp3',
			];

			$this->request(null, 'audio/speech', $params, 'POST', logErrors: false, serviceType: Application::SERVICE_TYPE_TTS);
		} catch (Exception $e) {
			return $e->getCode() !== Http::STATUS_NOT_FOUND && $e->getCode() !== Http::STATUS_UNAUTHORIZED;
		}
		return true;
	}

	/**
	 * Updates the admin config with the availability of the providers
	 *
	 * @return array the updated config
	 * @throws Exception
	 */
	public function autoDetectFeatures(): array {
		$config = [];
		$config['t2i_provider_enabled'] = $this->isT2IAvailable();
		$config['stt_provider_enabled'] = $this->isSTTAvailable();
		$config['tts_provider_enabled'] = $this->isTTSAvailable();
		$this->openAiSettingsService->setAdminConfig($config);
		return $config;
	}
}
