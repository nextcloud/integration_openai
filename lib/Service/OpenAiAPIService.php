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
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
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
		private ServicesService $servicesService,
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
	 * @param ServiceConfig $service the service the usage happened on
	 * @throws Exception If there is an error creating the quota usage.
	 */
	public function createQuotaUsage(string $userId, int $type, int $usage, ServiceConfig $service): void {
		$rule = $this->quotaRuleService->getRule($type, $userId, $service);
		$this->quotaUsageMapper->createQuotaUsage($userId, $type, $usage, $rule['pool'] ? $rule['id'] : -1, $service->getId());
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
	 * Get the model list of a service
	 *
	 * @param ?string $userId
	 * @param ServiceConfig $service
	 * @param bool $refresh whether to bypass the caches and make a network request
	 * @return array the model list response, with the models in the 'data' key
	 * @throws Exception
	 */
	public function getModels(?string $userId, ServiceConfig $service, bool $refresh = false): array {
		$serviceId = $service->getId();
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		$userCacheKey = Application::MODELS_CACHE_KEY . '_' . $serviceId . '_' . ($userId ?? '');
		$adminCacheKey = Application::MODELS_CACHE_KEY . '_' . $serviceId . '_main';
		$dbCacheKey = Application::MODELS_CACHE_KEY . '_' . $serviceId;

		if (!$refresh) {
			if (array_key_exists($serviceId, $this->modelsMemoryCache)) {
				$this->logger->debug('Getting OpenAI models from the memory cache');
				return $this->modelsMemoryCache[$serviceId];
			}

			// try to get models from the user cache first
			if ($userId !== null) {
				$userCachedModels = $cache->get($userCacheKey);
				if ($userCachedModels) {
					$this->logger->debug('Getting OpenAI models from user cache for user ' . $userId);
					$this->modelsMemoryCache[$serviceId] = $userCachedModels;
					return $userCachedModels;
				}
			}

			// if the user has their own credentials for this service, skip the admin cache
			if (!$this->servicesService->userHasOwnCredentials($userId, $service)) {
				// here we know there is either no user cache or userId is null
				// so if there are no user-defined service credentials
				// we try to get the models from the admin cache
				if ($adminCachedModels = $cache->get($adminCacheKey)) {
					$this->logger->debug('Getting OpenAI models from the main distributed cache');
					$this->modelsMemoryCache[$serviceId] = $adminCachedModels;
					return $adminCachedModels;
				}
			}

			// if we don't need to refresh the model list and it's not been found in the cache, it is obtained from the DB
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
			$this->modelsMemoryCache[$serviceId] = $newCache;
			return $newCache;
		}

		// we know we are refreshing so we clear the caches and make the network request
		$cache->remove($adminCacheKey);
		$cache->remove($userCacheKey);

		try {
			$this->logger->debug('Actually getting OpenAI models with a network request');
			$params = $service->isUsingOpenRouter() ? ['output_modalities' => 'all'] : [];
			$modelsResponse = $this->request($userId, $service, 'models', $params);
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
		$this->modelsMemoryCache[$serviceId] = $modelsResponse;
		// we always store the model list after getting it
		$modelsObjectString = json_encode($modelsResponse);
		$this->appConfig->setValueString(Application::APP_ID, $dbCacheKey, $modelsObjectString);
		return $modelsResponse;
	}

	/**
	 * Check whether quota is exceeded for a user
	 *
	 * @param string|null $userId
	 * @param int $type
	 * @param ServiceConfig $service the service the request would be made to
	 * @return bool
	 * @throws Exception
	 */
	public function isQuotaExceeded(?string $userId, int $type, ServiceConfig $service): bool {
		if ($userId === null) {
			$this->logger->warning('Cannot check quota for anonymous user', ['app' => Application::APP_ID]);
			return false;
		}

		if (!array_key_exists($type, Application::DEFAULT_QUOTAS)) {
			throw new Exception('Invalid quota type', Http::STATUS_BAD_REQUEST);
		}

		if ($this->servicesService->userHasOwnCredentials($userId, $service)) {
			// User has specified their own credentials for this service, no quota limit:
			return false;
		}
		$rule = $this->quotaRuleService->getRule($type, $userId, $service);
		$quota = $rule['amount'];
		$pool = $rule['pool'] ? $rule['id'] : null;
		// a matching quota rule is a global budget, the fallback quota is the one of the service
		$serviceId = $rule['id'] === null ? $service->getId() : null;

		if ($quota === 0) {
			//  Unlimited quota:
			return false;
		}

		$quotaStart = $this->openAiSettingsService->getQuotaStart();

		try {
			$quotaUsage = $this->quotaUsageMapper->getQuotaUnitsOfUserInTimePeriod($userId, $type, $quotaStart, $pool, $serviceId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|DBException|RuntimeException $e) {
			$this->logger->warning('Could not retrieve quota usage for user: ' . $userId . ' and quota type: ' . $type . '. Error: ' . $e->getMessage());
			throw new Exception('Could not retrieve quota usage.', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if ($quotaUsage < $quota) {
			return false;
		}
		$cache = $this->cacheFactory->createLocal(Application::APP_ID);
		if ($cache->get('quota_exceeded_' . $userId . '_' . $type . '_' . $service->getId()) === null) {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new DateTime())
				->setObject('quota_exceeded', (string)$type)
				->setSubject('quota_exceeded', ['type' => $type]);
			$this->notificationManager->notify($notification);
			$cache->set('quota_exceeded_' . $userId . '_' . $type . '_' . $service->getId(), true, 3600);
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
	 * Quota usage and limits of a user, per service
	 *
	 * @param string $userId
	 * @return array{services: list<array<string, mixed>>, period: array, start: int, end: int}
	 * @throws Exception
	 */
	public function getUserQuotaInfo(string $userId): array {
		$quotaPeriod = $this->openAiSettingsService->getQuotaPeriod();
		$quotaStart = $this->openAiSettingsService->getQuotaStart();
		$quotaEnd = $this->openAiSettingsService->getQuotaEnd();

		$services = [];
		foreach ($this->servicesService->getServices() as $service) {
			// if the user has their own credentials for a service, no quota applies to it
			$ownCredentials = $this->servicesService->userHasOwnCredentials($userId, $service);
			$quotaInfo = [];
			foreach (Application::DEFAULT_QUOTAS as $quotaType => $_) {
				$rule = $ownCredentials ? null : $this->quotaRuleService->getRule($quotaType, $userId, $service);
				// a matching quota rule is a global budget, the fallback quota is the one of the service
				$serviceId = ($rule === null || $rule['id'] === null) ? $service->getId() : null;
				$quotaInfo[$quotaType] = [
					'type' => $this->translatedQuotaType($quotaType),
					'unit' => $this->translatedQuotaUnit($quotaType),
					'limit' => $rule === null ? 0 : $rule['amount'],
				];
				try {
					$quotaInfo[$quotaType]['used'] = $this->quotaUsageMapper->getQuotaUnitsOfUserInTimePeriod(
						$userId, $quotaType, $quotaStart, null, $serviceId,
					);
					if ($rule !== null && $rule['pool']) {
						$quotaInfo[$quotaType]['used_pool'] = $this->quotaUsageMapper->getQuotaUnitsOfUserInTimePeriod(
							$userId, $quotaType, $quotaStart, $rule['id'], $serviceId,
						);
					}
				} catch (DoesNotExistException|MultipleObjectsReturnedException|DBException|RuntimeException $e) {
					$this->logger->warning('Could not retrieve quota usage for user: ' . $userId . ' and quota type: ' . $quotaType . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
					throw new Exception($this->l10n->t('Unknown error while retrieving quota usage.'), Http::STATUS_INTERNAL_SERVER_ERROR);
				}
			}
			$services[] = [
				'id' => $service->getId(),
				'name' => $service->getDisplayName(),
				'has_own_credentials' => $ownCredentials,
				'quota_usage' => $quotaInfo,
			];
		}

		return [
			'services' => $services,
			'period' => $quotaPeriod,
			'start' => $quotaStart,
			'end' => $quotaEnd,
		];
	}

	/**
	 * Instance-wide quota usage, per service
	 *
	 * @return list<array<string, mixed>>
	 * @throws Exception
	 */
	public function getAdminQuotaInfo(): array {
		$startTime = $this->openAiSettingsService->getQuotaStart();
		$services = [];
		foreach ($this->servicesService->getServices() as $service) {
			$quotaInfo = [];
			foreach (Application::DEFAULT_QUOTAS as $quotaType => $_) {
				$quotaInfo[$quotaType] = [
					'type' => $this->translatedQuotaType($quotaType),
					'unit' => $this->translatedQuotaUnit($quotaType),
					'limit' => $service->getQuota($quotaType),
				];
				try {
					$quotaInfo[$quotaType]['used'] = $this->quotaUsageMapper->getQuotaUnitsInTimePeriod($quotaType, $startTime, $service->getId());
				} catch (DoesNotExistException|MultipleObjectsReturnedException|DBException|RuntimeException $e) {
					$this->logger->warning('Could not retrieve quota usage for quota type: ' . $quotaType . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
					// We can pass detailed error info to the UI here since the user is an admin in any case:
					throw new Exception('Could not retrieve quota usage: ' . $e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
				}
			}
			$services[] = [
				'id' => $service->getId(),
				'name' => $service->getDisplayName(),
				'quota_usage' => $quotaInfo,
			];
		}
		return $services;
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
		ServiceConfig $service,
		string $prompt,
		int $n,
		string $model,
		?int $maxTokens = null,
		?array $extraParams = null,
	): array {

		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TEXT, $service)) {
			throw new Exception($this->l10n->t('Text generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$maxTokensLimit = $service->getMaxTokens();
		if ($maxTokens === null || $maxTokens > $maxTokensLimit) {
			$maxTokens = $maxTokensLimit;
		}

		$params = [];
		$modelParam = $this->modelParam($service, $model, Application::DEFAULT_COMPLETION_MODEL_ID);
		if ($modelParam !== null) {
			$params['model'] = $modelParam;
		}
		$params['prompt'] = $prompt;
		$params['max_tokens'] = $maxTokens;
		$params['n'] = $n;
		if ($userId !== null) {
			$params['user'] = $userId;
		}

		$adminExtraParams = $service->getLlmExtraParamsArray();
		if ($adminExtraParams !== null) {
			$params = array_merge($adminExtraParams, $params);
		}
		if ($extraParams !== null) {
			$params = array_merge($extraParams, $params);
		}

		$response = $this->request($userId, $service, 'completions', $params, 'POST');

		if (!isset($response['choices'])) {
			$this->logger->warning('Text generation error: ' . json_encode($response));
			throw new Exception($this->l10n->t('Unknown text generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (isset($response['usage'], $response['usage']['total_tokens'])) {
			$usage = $response['usage']['total_tokens'];
			try {
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage, $service);
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
		ServiceConfig $service,
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
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TEXT, $service)) {
			throw new Exception($this->l10n->t('Text generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$params = $this->buildChatCompletionRequestParams(
			$userId,
			$service,
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
			$service,
			'chat/completions',
			$params,
			'POST',
			null,
			true,
			0,
			true,
		);

		$streamResult = yield from $this->streamingService->parseStreamChatResponse($response);

		if (isset($streamResult['usage']['total_tokens'])) {
			$usage = $streamResult['usage']['total_tokens'];
			try {
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage, $service);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TEXT . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}

		return $this->normalizeChatCompletionResponse($streamResult);
	}

	public function createChatCompletion(
		?string $userId,
		ServiceConfig $service,
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
			$userId, $service, $model, $userPrompt, $systemPrompt, $history,
			$n, $maxTokens, $extraParams, $toolMessage, $tools, $files,
			false,
		);

		if (isset($response['usage'], $response['usage']['total_tokens'])) {
			$usage = $response['usage']['total_tokens'];
			try {
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage, $service);
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
		ServiceConfig $service,
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
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TEXT, $service)) {
			throw new Exception($this->l10n->t('Text generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$params = $this->buildChatCompletionRequestParams(
			$userId,
			$service,
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

		return $this->request($userId, $service, 'chat/completions', $params, 'POST');
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
		ServiceConfig $service,
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
		$modelRequestParam = $this->modelParam($service, $model, Application::DEFAULT_COMPLETION_MODEL_ID);

		$messages = [];
		if ($systemPrompt !== null) {
			$messages[] = [
				// o1-* models don't support system messages
				// system prompts as a user message seems to work fine though
				'role' => ($service->isUsingOpenAi() && str_starts_with($modelRequestParam ?? '', 'o1-'))
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
				// Handle file attachments in the history
				if (isset($message['content']) && is_array($message['content'])) {
					$content = [];
					foreach ($message['content'] as $item) {
						if (!isset($item['type'])) {
							throw new UserFacingProcessingException(
								'Invalid message history content',
								0,
								null,
								$this->l10n->t('Invalid message history content'),
							);
						}
						if ($item['type'] === 'file') {
							if (!isset($item['file_id'])) {
								throw new UserFacingProcessingException(
									'Invalid message history content',
									0,
									null,
									$this->l10n->t('Invalid message history content'),
								);
							}
							// If the history contains a file that isn't supported anymore we should skip it so the chat isn't broken
							try {
								$content = array_merge($content, $this->openAiFileService->buildFileContentFromId($item['file_id'], $userId, $item['ocp_task_id'] ?? null, $service));
							} catch (ProcessingException|UserFacingProcessingException $e) {
								$this->logger->warning('Could not build file content from id: ' . $item['file_id'] . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
							}
						} else {
							$content[] = $item;
						}
					}
					$message['content'] = $content;
				}
				$messages[] = $message;
			}
		}
		// Attach all files when necessary
		if ($files !== null && count($files) > 0) {
			if (count($files) > 500) {
				throw new UserFacingProcessingException($this->l10n->t('Too many files. Max is 500'), Http::STATUS_BAD_REQUEST);
			}
			$content = [];
			foreach ($files as $file) {
				$content = array_merge($content, $this->openAiFileService->buildFileContentFromFile($file, $service));
			}
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

		$params = [];
		if ($modelRequestParam !== null) {
			$params['model'] = $modelRequestParam;
		}
		$params['messages'] = $messages;
		$params['n'] = $n;
		$params['stream'] = $stream;

		$maxTokensLimit = $service->getMaxTokens();
		if ($maxTokens === null || $maxTokens > $maxTokensLimit) {
			$maxTokens = $maxTokensLimit;
		}
		if ($service->getUseMaxCompletionTokensParam()) {
			// max_tokens is now deprecated https://platform.openai.com/docs/api-reference/chat/create
			$params['max_completion_tokens'] = $maxTokens;
		} else {
			$params['max_tokens'] = $maxTokens;
		}

		if ($tools !== null) {
			$params['tools'] = $tools;
		}
		if ($userId !== null && $service->isUsingOpenAi()) {
			$params['user'] = $userId;
		}

		$adminExtraParams = $service->getLlmExtraParamsArray();
		if ($adminExtraParams !== null) {
			$params = array_merge($adminExtraParams, $params);
		}
		if ($extraParams !== null) {
			$params = array_merge($extraParams, $params);
		}
		if ($stream && $service->isUsingOpenAi()) {
			$params['stream_options'] = array_merge(
				is_array($params['stream_options'] ?? null) ? $params['stream_options'] : [],
				['include_usage' => true],
			);
		}

		return $params;
	}

	/**
	 * The value of the model request parameter, or null when it should not be
	 * sent at all.
	 *
	 * The "Default" pseudo model means the service serves one fixed model and
	 * does not expect the parameter. OpenAI always requires it, so we fall back
	 * to a sensible default there.
	 */
	private function modelParam(ServiceConfig $service, string $model, string $openAiFallback): ?string {
		if ($model !== Application::DEFAULT_MODEL_ID) {
			return $model;
		}
		return $service->isUsingOpenAi() ? $openAiFallback : null;
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
		ServiceConfig $service,
		string $audioBase64,
		bool $translate = true,
		string $model = Application::DEFAULT_MODEL_ID,
	): string {
		return $this->transcribe(
			$userId,
			$service,
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
		ServiceConfig $service,
		File $file,
		bool $translate = false,
		string $model = Application::DEFAULT_MODEL_ID,
		string $language = 'default',
		string $responseFormat = 'verbose_json',
	): string {
		try {
			$transcriptionResponse = $this->transcribe($userId, $service, $file->getContent(), $translate, $model, $language, $responseFormat);
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
		ServiceConfig $service,
		string $audioFileContent,
		bool $translate = true,
		string $model = Application::DEFAULT_MODEL_ID,
		string $language = 'default',
		string $responseFormat = 'verbose_json', // Verbose needed for extraction of audio duration
	): string {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_TRANSCRIPTION, $service)) {
			throw new Exception($this->l10n->t('Audio transcription quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$params = [];
		$modelParam = $this->modelParam($service, $model, Application::DEFAULT_TRANSCRIPTION_MODEL_ID);
		if ($modelParam !== null) {
			$params['model'] = $modelParam;
		}
		$params['file'] = $audioFileContent;
		$params['response_format'] = $responseFormat;
		// Gets the user's preferred language if it's not the default one
		if ($language === 'default') {
			$language = $this->openAiSettingsService->getUserSTTLanguage($userId);
		}
		if ($language !== 'detect_language') {
			$params['language'] = $language;
		}
		$endpoint = $translate ? 'audio/translations' : 'audio/transcriptions';
		$contentType = 'multipart/form-data';

		$response = $this->request($userId, $service, $endpoint, $params, 'POST', $contentType);

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
					$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TRANSCRIPTION, $audioDuration, $service);
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
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TRANSCRIPTION, $audioDuration, $service);
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
		ServiceConfig $service,
		string $prompt,
		string $model,
		int $n = 1,
		string $size = Application::DEFAULT_DEFAULT_IMAGE_SIZE,
	): array {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_IMAGE, $service)) {
			throw new Exception($this->l10n->t('Image generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$params = [
			'prompt' => $prompt,
			'size' => $size,
			'n' => $n,
		];
		$modelParam = $this->modelParam($service, $model, Application::DEFAULT_IMAGE_MODEL_ID);
		if ($modelParam !== null) {
			$params['model'] = $modelParam;
		}

		$apiResponse = $this->request($userId, $service, 'images/generations', $params, 'POST');

		if (!isset($apiResponse['data']) || !is_array($apiResponse['data'])) {
			$this->logger->warning('OpenAI image generation error', ['api_response' => $apiResponse]);
			throw new Exception($this->l10n->t('Unknown image generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		} else {
			try {
				$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_IMAGE, $n, $service);
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
	public function getImageRequestOptions(?string $userId, ServiceConfig $service): array {
		$service = $this->servicesService->applyUserCredentials($service, $userId);
		$requestOptions = [
			'timeout' => $service->getRequestTimeout(),
			'headers' => [
				'User-Agent' => Application::USER_AGENT,
			],
		];

		if ($service->getImageRequestAuth()) {
			if ($service->getUseBasicAuth()) {
				if ($service->getBasicUser() !== '' && $service->getBasicPassword() !== '') {
					$requestOptions['headers']['Authorization'] = 'Basic ' . base64_encode($service->getBasicUser() . ':' . $service->getBasicPassword());
				}
			} else {
				$requestOptions['headers']['Authorization'] = 'Bearer ' . $service->getApiKey();
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
		ServiceConfig $service,
		string $prompt,
		string $model,
		string $voice,
		float $speed = 1,
	): array {
		if ($this->isQuotaExceeded($userId, Application::QUOTA_TYPE_SPEECH, $service)) {
			throw new Exception($this->l10n->t('Speech generation quota exceeded'), Http::STATUS_TOO_MANY_REQUESTS);
		}

		$params = [
			'input' => $prompt,
			'voice' => $voice === Application::DEFAULT_MODEL_ID ? Application::DEFAULT_SPEECH_VOICE : $voice,
		];
		$modelParam = $this->modelParam($service, $model, Application::DEFAULT_SPEECH_MODEL_ID);
		if ($modelParam !== null) {
			$params['model'] = $modelParam;
		}
		$params['response_format'] = 'mp3';
		$params['speed'] = $speed;

		$apiResponse = $this->request($userId, $service, 'audio/speech', $params, 'POST');

		try {
			$charCount = mb_strlen($prompt);
			$this->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_SPEECH, $charCount, $service);
		} catch (DBException $e) {
			$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_SPEECH . '. Error: ' . $e->getMessage());
		}
		return $apiResponse;
	}

	/**
	 * @return int
	 */
	public function getExpTextProcessingTime(ServiceConfig $service): int {
		return $service->isUsingOpenAi()
			? intval($this->appConfig->getValueString(Application::APP_ID, 'openai_text_generation_time', strval(Application::DEFAULT_OPENAI_TEXT_GENERATION_TIME), lazy: true))
			: intval($this->appConfig->getValueString(Application::APP_ID, 'localai_text_generation_time', strval(Application::DEFAULT_LOCALAI_TEXT_GENERATION_TIME), lazy: true));
	}

	/**
	 * @param int $runtime
	 * @return void
	 */
	public function updateExpTextProcessingTime(int $runtime, ServiceConfig $service): void {
		$oldTime = floatval($this->getExpTextProcessingTime($service));
		$newTime = (1.0 - Application::EXPECTED_RUNTIME_LOWPASS_FACTOR) * $oldTime + Application::EXPECTED_RUNTIME_LOWPASS_FACTOR * floatval($runtime);

		if ($service->isUsingOpenAi()) {
			$this->appConfig->setValueString(Application::APP_ID, 'openai_text_generation_time', strval(intval($newTime)), lazy: true);
		} else {
			$this->appConfig->setValueString(Application::APP_ID, 'localai_text_generation_time', strval(intval($newTime)), lazy: true);
		}
	}

	/**
	 * @return int
	 */
	public function getExpImgProcessingTime(ServiceConfig $service): int {
		return $service->isUsingOpenAi()
			? intval($this->appConfig->getValueString(Application::APP_ID, 'openai_image_generation_time', strval(Application::DEFAULT_OPENAI_IMAGE_GENERATION_TIME), lazy: true))
			: intval($this->appConfig->getValueString(Application::APP_ID, 'localai_image_generation_time', strval(Application::DEFAULT_LOCALAI_IMAGE_GENERATION_TIME), lazy: true));
	}

	/**
	 * @param int $runtime
	 * @return void
	 */
	public function updateExpImgProcessingTime(int $runtime, ServiceConfig $service): void {
		$oldTime = floatval($this->getExpImgProcessingTime($service));
		$newTime = (1.0 - Application::EXPECTED_RUNTIME_LOWPASS_FACTOR) * $oldTime + Application::EXPECTED_RUNTIME_LOWPASS_FACTOR * floatval($runtime);

		if ($service->isUsingOpenAi()) {
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
	 * @param int $retryCount number of retries that have been attempted so far
	 * @return array decoded request result or error
	 * @throws Exception|UserFacingProcessingException
	 */
	public function request(
		?string $userId, ServiceConfig $service, string $endPoint, array $params = [], string $method = 'GET',
		?string $contentType = null, bool $logErrors = true,
		int $retryCount = 0,
		bool $stream = false,
	): array {
		try {
			// the user's own credentials take precedence over the admin ones
			$service = $this->servicesService->applyUserCredentials($service, $userId);
			$serviceUrl = $service->getRequestUrl();
			$apiKey = $service->getApiKey();
			$basicUser = $service->getBasicUser();
			$basicPassword = $service->getBasicPassword();
			$useBasicAuth = $service->getUseBasicAuth();
			$timeout = $service->getRequestTimeout();

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

			if ($service->isUsingOpenAi() || !$useBasicAuth) {
				if ($apiKey !== '') {
					$options['headers']['Authorization'] = 'Bearer ' . $apiKey;
				}
			} else {
				if ($basicUser !== '' && $basicPassword !== '') {
					$options['headers']['Authorization'] = 'Basic ' . base64_encode($basicUser . ':' . $basicPassword);
				}
			}

			if (!$service->isUsingOpenAi()) {
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
					return $this->request($userId, $service, $endPoint, $params, $method, $contentType, $logErrors, $retryCount + 1, $stream);
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
					userFacingMessage: $this->l10n->t('%s API error: Invalid API key or invalid Basic Authentication. Contact your system administrator.', [$service->getDisplayName()]),
				);
			}
			if ($e->getResponse()->getStatusCode() >= 500) {
				throw new UserFacingProcessingException(
					$this->l10n->t('API request error: ') . $errorMessage,
					intval($e->getCode()),
					userFacingMessage: $this->l10n->t('%s API error: AI backend is currently not available. Contact your system administrator.', [$service->getDisplayName()]),
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
				userFacingMessage: $this->l10n->t('%s API error: AI backend is currently not reachable. Contact your system administrator.', [$service->getDisplayName()]),
			);
		}
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

			if (isset($choice['message']['content'])) {
				if (is_string($choice['message']['content'])) {
					$completions['messages'][] = $choice['message']['content'];
				} elseif (is_array($choice['message']['content'])) {
					$messageContent = [];
					// Handles more complex mistral message content (TODO: missing reasoning for now look at https://github.com/nextcloud/integration_openai/pull/409#discussion_r3638108824)
					foreach ($choice['message']['content'] as $content) {
						if ($content['type'] === 'text' && is_string($content['text'])) {
							$messageContent[] = $content['text'];
						}
					}
					$completions['messages'][] = implode('', $messageContent);
				}
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
	 * Check whether a service can generate images
	 */
	public function isT2IAvailable(ServiceConfig $service): bool {
		if ($service->isUsingOpenAi()) {
			return true;
		}
		try {
			$params = [
				'prompt' => 'a',
				'model' => 'invalid-model',
			];
			$this->request(null, $service, 'images/generations', $params, 'POST', logErrors: false);
		} catch (Exception $e) {
			return $e->getCode() !== Http::STATUS_NOT_FOUND && $e->getCode() !== Http::STATUS_UNAUTHORIZED;
		}
		return true;
	}

	/**
	 * Check whether a service can transcribe audio
	 */
	public function isSTTAvailable(ServiceConfig $service): bool {
		if ($service->isUsingOpenAi()) {
			return true;
		}
		try {
			$params = [
				'model' => 'invalid-model',
				'file' => 'a',
			];
			$this->request(null, $service, 'audio/translations', $params, 'POST', 'multipart/form-data', logErrors: false);
		} catch (Exception $e) {
			return $e->getCode() !== Http::STATUS_NOT_FOUND && $e->getCode() !== Http::STATUS_UNAUTHORIZED;
		}
		return true;
	}

	/**
	 * Check whether a service can generate speech
	 */
	public function isTTSAvailable(ServiceConfig $service): bool {
		if ($service->isUsingOpenAi()) {
			return true;
		}
		try {
			$params = [
				'input' => 'a',
				'voice' => 'invalid-voice',
				'model' => 'invalid-model',
				'response_format' => 'mp3',
			];

			$this->request(null, $service, 'audio/speech', $params, 'POST', logErrors: false);
		} catch (Exception $e) {
			return $e->getCode() !== Http::STATUS_NOT_FOUND && $e->getCode() !== Http::STATUS_UNAUTHORIZED;
		}
		return true;
	}

	/**
	 * Detect which modalities a service supports and switch off the ones it
	 * does not. The text modality is always assumed to be available.
	 *
	 * @return array<string, bool> the detected modality switches
	 * @throws Exception
	 */
	public function autoDetectModalities(ServiceConfig $service): array {
		$detected = [
			'image_enabled' => $this->isT2IAvailable($service),
			'stt_enabled' => $this->isSTTAvailable($service),
			'tts_enabled' => $this->isTTSAvailable($service),
		];
		$this->servicesService->updateService($service->getId(), $detected);
		return $detected;
	}
}
