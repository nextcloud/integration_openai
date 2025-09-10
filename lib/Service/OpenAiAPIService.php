<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use DateTime;
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
use OCP\Notification\IManager as INotificationManager;
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

	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IAppConfig $appConfig,
		private ICacheFactory $cacheFactory,
		private QuotaUsageMapper $quotaUsageMapper,
		private OpenAiSettingsService $openAiSettingsService,
		private INotificationManager $notificationManager,
		private QuotaRuleService $quotaRuleService,
		IClientService $clientService,
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * @param string $userId
	 * @param int $type
	 * @param int $usage
	 * @throws Exception If there is an error creating the quota usage.
	 */
	public function createQuotaUsage(string $userId, int $type, int $usage) {
		$rule = $this->quotaRuleService->getRule($type, $userId);
		$this->quotaUsageMapper->createQuotaUsage($userId, $type, $usage, $rule['pool'] ? $rule['id'] : -1);
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
	 * @param ?string $userId
	 * @param bool $refresh
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getModels(?string $userId, bool $refresh = false): array {
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		$userCacheKey = Application::MODELS_CACHE_KEY . '_' . ($userId ?? '');
		$adminCacheKey = Application::MODELS_CACHE_KEY . '-main';

		if (!$refresh) {
			if ($this->modelsMemoryCache !== null) {
				$this->logger->debug('Getting OpenAI models from the memory cache');
				return $this->modelsMemoryCache;
			}

			// try to get models from the user cache first
			if ($userId !== null) {
				$userCachedModels = $cache->get($userCacheKey);
				if ($userCachedModels) {
					$this->logger->debug('Getting OpenAI models from user cache for user ' . $userId);
					$this->modelsMemoryCache = $userCachedModels;
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
					$this->modelsMemoryCache = $adminCachedModels;
					return $adminCachedModels;
				}
			}

			// if we don't need to refresh to model list and it's not been found in the cache, it is obtained from the DB
			$modelsObjectString = $this->appConfig->getValueString(Application::APP_ID, 'models', '{"data":[],"object":"list"}');
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
			$this->modelsMemoryCache = $newCache;
			return $newCache;
		}

		// we know we are refreshing so we clear the caches and make the network request
		$cache->remove($adminCacheKey);
		$cache->remove($userCacheKey);

		try {
			$this->logger->debug('Actually getting OpenAI models with a network request');
			$modelsResponse = $this->request($userId, 'models');
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
		$this->modelsMemoryCache = $modelsResponse;
		// we always store the model list after getting it
		$modelsObjectString = json_encode($modelsResponse);
		$this->appConfig->setValueString(Application::APP_ID, 'models', $modelsObjectString);
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
	 * @param string|null $userAudioPromptBase64
	 * @return array{messages: array<string>, tool_calls: array<string>, audio_messages: list<array<string, mixed>>}
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
		?string $userAudioPromptBase64 = null,
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
		if ($userAudioPromptBase64 !== null) {
			// if there is audio, use the new message format (content is a list of objects)
			$message = [
				'role' => 'user',
				'content' => [
					[
						'type' => 'input_audio',
						'input_audio' => [
							'data' => $userAudioPromptBase64,
							'format' => 'mp3',
						],
					],
				],
			];
			if ($userPrompt !== null) {
				$message['content'][] = [
					'type' => 'text',
					'text' => $userPrompt,
				];
			}
			$messages[] = $message;
		} elseif ($userPrompt !== null) {
			// if there is only text, use the old message format (content is a string)
			$messages[] = [
				'role' => 'user',
				'content' => $userPrompt,
			];
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
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TEXT . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		$completions = [
			'messages' => [],
			'tool_calls' => [],
			'audio_messages' => [],
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
			if (isset($choice['message']['audio'], $choice['message']['audio']['data']) && is_string($choice['message']['audio']['data'])) {
				$completions['audio_messages'][] = $choice['message'];
			}
		}

		return $completions;
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
	 * @return string
	 * @throws Exception
	 */
	public function transcribeFile(
		?string $userId,
		File $file,
		bool $translate = false,
		string $model = Application::DEFAULT_MODEL_ID,
		string $language = 'default',
	): string {
		try {
			$transcriptionResponse = $this->transcribe($userId, $file->getContent(), $translate, $model, $language);
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
	 * @return string
	 * @throws Exception
	 */
	public function transcribe(
		?string $userId,
		string $audioFileContent,
		bool $translate = true,
		string $model = Application::DEFAULT_MODEL_ID,
		string $language = 'default',
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
		// Gets the user's preferred language if it's not the default one
		if ($language === 'default') {
			$language = $this->openAiSettingsService->getUserSTTLanguage($userId);
		}
		if ($language !== 'detect_language') {
			$params['language'] = $language;
		}
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
	 * @param float $speed
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
		$oldTime = floatval($this->getExpImgProcessingTime());
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
		return $this->isUsingOpenAi()
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

		if ($this->isUsingOpenAi()) {
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
	 * @return array decoded request result or error
	 * @throws Exception
	 */
	public function request(?string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $contentType = null, bool $logErrors = true): array {
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
			if (str_starts_with(strtolower($response->getHeader('Content-Type')), 'application/json')) {
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
			if ($logErrors) {
				if ($e->getResponse()->getStatusCode() === 404) {
					$this->logger->debug('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
				} else {
					$this->logger->warning('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
				}
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

	/**
	 * Check if the T2I provider is available
	 *
	 * @return bool whether the T2I provider is available
	 */
	public function isT2IAvailable(): bool {
		if ($this->isUsingOpenAi()) {
			return true;
		}
		try {
			$params = [
				'prompt' => 'a',
				'model' => 'invalid-model',
			];
			$this->request(null, 'images/generations', $params, 'POST', logErrors: false);
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
		if ($this->isUsingOpenAi()) {
			return true;
		}
		try {
			$params = [
				'model' => 'invalid-model',
				'file' => 'a',
			];
			$this->request(null, 'audio/translations', $params, 'POST', 'multipart/form-data', logErrors: false);
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
		if ($this->isUsingOpenAi()) {
			return true;
		}
		try {
			$params = [
				'input' => 'a',
				'voice' => 'invalid-voice',
				'model' => 'invalid-model',
				'response_format' => 'mp3',
			];

			$this->request(null, 'audio/speech', $params, 'POST', logErrors: false);
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
		$config['analyze_image_provider_enabled'] = $this->openAiSettingsService->getAnalyzeImageProviderEnabled();
		return $config;
	}
}
