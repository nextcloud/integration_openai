<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\Service;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\Watsonx\AppInfo\Application;
use OCA\Watsonx\Db\QuotaUsageMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\Db\Exception as DBException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\TaskProcessing\ShapeEnumValue;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service to make requests to watsonx.ai REST API
 */
class WatsonxAPIService {
	private IClient $client;
	private ?array $modelsMemoryCache = null;
	private ?string $accessTokenMemoryCache = null;
	private ?bool $areCredsValid = null;

	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IAppConfig $appConfig,
		private ICacheFactory $cacheFactory,
		private QuotaUsageMapper $quotaUsageMapper,
		private WatsonxSettingsService $watsonxSettingsService,
		IClientService $clientService,
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * @return string
	 */
	public function getServiceName(): string {
		return $this->watsonxSettingsService->getServiceName() ?: 'IBM watsonx.ai';
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
			if (!isset($model['model_id'])) {
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
			$this->logger->info('Cannot get watsonx.ai models without an API key');
			return [];
		} elseif ($this->areCredsValid === null) {
			if ($this->watsonxSettingsService->getUserApiKey($userId, true) === '') {
				$this->areCredsValid = false;
				$this->logger->info('Cannot get watsonx.ai models without an API key');
				return [];
			}
			$this->areCredsValid = true;
		}

		if ($this->modelsMemoryCache !== null) {
			$this->logger->debug('Getting watsonx.ai models from the memory cache');
			return $this->modelsMemoryCache;
		}

		$cacheKey = Application::MODELS_CACHE_KEY;
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		if ($cachedModels = $cache->get($cacheKey)) {
			$this->logger->debug('Getting watsonx.ai models from distributed cache');
			return $cachedModels;
		}

		try {
			$this->logger->debug('Actually getting watsonx.ai models with a network request');
			$params = [
				'version' => Application::WATSONX_API_VERSION,
			];
			$modelsResponse = $this->request($userId, '/ml/v1/foundation_model_specs', $params);
		} catch (Exception $e) {
			$this->logger->warning('Error retrieving models (exc): ' . $e->getMessage());
			$this->areCredsValid = false;
			throw $e;
		}
		if (isset($modelsResponse['error'])) {
			$this->logger->warning('Error retrieving models: ' . \json_encode($modelsResponse));
			$this->areCredsValid = false;
			throw new Exception($modelsResponse['error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// Assume all models are listed on first page
		// TODO: Retrieve additional models past the first page
		$modelsResponse = $modelsResponse['resources'];
		if (!$this->isModelListValid($modelsResponse)) {
			$this->logger->warning('Invalid models response: ' . \json_encode($modelsResponse));
			$this->areCredsValid = false;
			throw new Exception($this->l10n->t('Invalid models response received'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$cache->set($cacheKey, $modelsResponse, Application::MODELS_CACHE_TTL);
		$this->modelsMemoryCache = $modelsResponse;
		$this->areCredsValid = true;
		return $modelsResponse;
	}

	/**
	 * @param string $apiKey
	 * @return string
	 * @throws Exception
	 */
	public function getAccessToken(string $apiKey): string {
		if ($this->accessTokenMemoryCache !== null) {
			$this->logger->debug('Getting watsonx.ai access token from the memory cache');
			return $this->accessTokenMemoryCache;
		}

		$cacheKey = Application::ACCESS_TOKEN_CACHE_KEY;
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		if ($cachedAccessToken = $cache->get($cacheKey)) {
			$this->logger->debug('Getting watsonx.ai access token from distributed cache');
			return $cachedAccessToken;
		}

		try {
			$this->logger->debug('Actually getting IBM access token with a network request');
			$params = [
				'grant_type' => 'urn:ibm:params:oauth:grant-type:apikey',
				'apikey' => $apiKey,
			];
			$accessTokenResponse = $this->requestIAM('/identity/token', $params, 'POST');
		} catch (Exception $e) {
			$this->logger->warning('Error retrieving access token (exc): ' . $e->getMessage());
			$this->areCredsValid = false;
			throw $e;
		}
		if (isset($accessTokenResponse['error'])) {
			$this->logger->warning('Error retrieving access token: ' . \json_encode($accessTokenResponse));
			$this->areCredsValid = false;
			throw new Exception($accessTokenResponse['error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$accessToken = $accessTokenResponse['access_token'];
		$cache->set($cacheKey, $accessToken, $accessTokenResponse['expires_in']);
		$this->accessTokenMemoryCache = $accessToken;
		$this->areCredsValid = true;
		return $accessToken;
	}

	/**
	 * @param string $userId
	 */
	private function hasOwnWatsonxApiKey(string $userId): bool {
		return $this->watsonxSettingsService->getUserApiKey($userId) !== '';
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
				return new ShapeEnumValue($model['model_id'], $model['model_id']);
			}, $modelResponse);
			array_unshift($modelEnumValues, new ShapeEnumValue($this->l10n->t('Default'), 'Default'));
			return $modelEnumValues;
		} catch (\Throwable $e) {
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

		if ($this->hasOwnWatsonxApiKey($userId)) {
			// User has specified own watsonx.ai API key, no quota limit:
			return false;
		}

		// Get quota limits
		$quota = $this->watsonxSettingsService->getQuotas()[$type];

		if ($quota === 0) {
			//  Unlimited quota:
			return false;
		}

		$quotaPeriod = $this->watsonxSettingsService->getQuotaPeriod();

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
		return $this->l10n->t($type === Application::QUOTA_TYPE_TEXT ? 'Text generation' : 'Unknown');
	}

	/**
	 * Get translated unit of quota type
	 *
	 * @param int $type
	 */
	public function translatedQuotaUnit(int $type): string {
		return $this->l10n->t($type === Application::QUOTA_TYPE_TEXT ? 'tokens' : 'Unknown');
	}

	/**
	 * @param string $userId
	 * @return array
	 * @throws Exception
	 */
	public function getUserQuotaInfo(string $userId): array {
		// Get quota limits (if the user has specified an own watsonx.ai API key, no quota limit, just supply default values as fillers)
		$quotas = $this->hasOwnWatsonxApiKey($userId) ? Application::DEFAULT_QUOTAS : $this->watsonxSettingsService->getQuotas();
		// Get quota period
		$quotaPeriod = $this->watsonxSettingsService->getQuotaPeriod();
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
		$quotaPeriod = $this->watsonxSettingsService->getQuotaPeriod();
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
	 * @return array|string[]
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

		$maxTokensLimit = $this->watsonxSettingsService->getMaxTokens();
		if ($maxTokens === null || $maxTokens > $maxTokensLimit) {
			$maxTokens = $maxTokensLimit;
		}

		$params = [
			'model_id' => $model === Application::DEFAULT_MODEL_ID ? Application::DEFAULT_COMPLETION_MODEL_ID : $model,
			'input' => $prompt,
			'parameters' => [
				'max_new_tokens' => $maxTokens,
				'time_limit' => $this->watsonxSettingsService->getRequestTimeout() * 1000,
			],
			// 'n' => $n,
		];

		if ($this->watsonxSettingsService->getUserApiKey($userId)) {
			$userProjectId = $this->watsonxSettingsService->getUserProjectId($userId);
			if ($userProjectId) {
				$params['project_id'] = $userProjectId;
			}
			$userSpaceId = $this->watsonxSettingsService->getUserSpaceId($userId);
			if ($userSpaceId) {
				$params['space_id'] = $userSpaceId;
			}
		} else {
			$adminProjectId = $this->watsonxSettingsService->getAdminProjectId();
			if ($adminProjectId) {
				$params['project_id'] = $adminProjectId;
			}
			$adminSpaceId = $this->watsonxSettingsService->getAdminSpaceId();
			if ($adminSpaceId) {
				$params['space_id'] = $adminSpaceId;
			}
		}

		$adminExtraParams = $this->getAdminExtraParams('llm_extra_params');
		if ($adminExtraParams !== null) {
			$params = array_merge($adminExtraParams, $params);
		}
		if ($extraParams !== null) {
			$params = array_merge($extraParams, $params);
		}

		$response = $this->request($userId, '/ml/v1/text/generation?version=' . Application::WATSONX_API_VERSION, $params, 'POST');

		if (!isset($response['results'])) {
			$this->logger->warning('Text generation error: ' . json_encode($response));
			throw new Exception($this->l10n->t('Unknown text generation error'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if (isset($response['results']['generated_token_count'])) {
			$usage = $response['results']['generated_token_count'];
			try {
				$this->quotaUsageMapper->createQuotaUsage($userId ?? '', Application::QUOTA_TYPE_TEXT, $usage);
			} catch (DBException $e) {
				$this->logger->warning('Could not create quota usage for user: ' . $userId . ' and quota type: ' . Application::QUOTA_TYPE_TEXT . '. Error: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}

		$completions = array_map(static fn (array $result): string => $result['generated_text'], $response['results']);
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
				'role' => 'system',
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
			$messages[] = [
				'role' => 'user',
				'content' => [
					[
						'type' => 'text',
						'text' => $userPrompt,
					],
				]
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
			'model_id' => $modelRequestParam,
			'messages' => $messages,
			'n' => $n,
			'time_limit' => $this->watsonxSettingsService->getRequestTimeout() * 1000,
		];

		$maxTokensLimit = $this->watsonxSettingsService->getMaxTokens();
		if ($maxTokens === null || $maxTokens > $maxTokensLimit) {
			$maxTokens = $maxTokensLimit;
		}
		$params['max_tokens'] = $maxTokens;

		if ($tools !== null) {
			$params['tools'] = $tools;
		}

		if ($this->watsonxSettingsService->getUserApiKey($userId)) {
			$userProjectId = $this->watsonxSettingsService->getUserProjectId($userId);
			if ($userProjectId) {
				$params['project_id'] = $userProjectId;
			}
			$userSpaceId = $this->watsonxSettingsService->getUserSpaceId($userId);
			if ($userSpaceId) {
				$params['space_id'] = $userSpaceId;
			}
		} else {
			$adminProjectId = $this->watsonxSettingsService->getAdminProjectId();
			if ($adminProjectId) {
				$params['project_id'] = $adminProjectId;
			}
			$adminSpaceId = $this->watsonxSettingsService->getAdminSpaceId();
			if ($adminSpaceId) {
				$params['space_id'] = $adminSpaceId;
			}
		}

		$adminExtraParams = $this->getAdminExtraParams('llm_extra_params');
		if ($adminExtraParams !== null) {
			$params = array_merge($adminExtraParams, $params);
		}
		if ($extraParams !== null) {
			$params = array_merge($extraParams, $params);
		}

		$response = $this->request($userId, '/ml/v1/text/chat?version=' . Application::WATSONX_API_VERSION, $params, 'POST');

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
	 * @return int
	 */
	public function getExpTextProcessingTime(): int {
		return intval($this->appConfig->getValueString(Application::APP_ID, 'watsonx_text_generation_time', strval(Application::DEFAULT_WATSONX_TEXT_GENERATION_TIME)));
	}

	/**
	 * @param int $runtime
	 * @return void
	 */
	public function updateExpTextProcessingTime(int $runtime): void {
		$oldTime = floatval($this->getExpTextProcessingTime());
		$newTime = (1.0 - Application::EXPECTED_RUNTIME_LOWPASS_FACTOR) * $oldTime + Application::EXPECTED_RUNTIME_LOWPASS_FACTOR * floatval($runtime);

		$this->appConfig->setValueString(Application::APP_ID, 'watsonx_text_generation_time', strval(intval($newTime)));
	}

	/**
	 * Make an HTTP request to the watsonx.ai API
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
			// an API key is mandatory when using watsonx.ai
			$apiKey = $this->watsonxSettingsService->getUserApiKey($userId, true);

			if (!$apiKey) {
				return ['error' => 'An API key is required for watsonx.ai'];
			}

			$serviceUrl = $this->watsonxSettingsService->getServiceUrl();
			if ($serviceUrl === '') {
				$serviceUrl = Application::WATSONX_API_BASE_URL;
			}

			$url = rtrim($serviceUrl, '/') . $endPoint;
			$options = [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->getAccessToken($apiKey),
					'User-Agent' => Application::USER_AGENT,
				],
			];

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
		} catch (ClientException|ServerException $e) {
			$responseBody = $e->getResponse()->getBody();
			$parsedResponseBody = json_decode($responseBody, true);
			if ($e->getResponse()->getStatusCode() === 404) {
				$this->logger->debug('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			} else {
				$this->logger->warning('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			}
			if (isset($parsedResponseBody['error']) && isset($parsedResponseBody['error']['message'])) {
				throw new Exception($this->l10n->t('API request error: ') . $parsedResponseBody['error']['message'], intval($e->getCode()));
			} else {
				throw new Exception($this->l10n->t('API request error: ') . $e->getMessage(), intval($e->getCode()));
			}
		}
	}

	/**
	 * Make an HTTP request to the IAM Identity Services API
	 * @param string $endPoint The path to reach
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array decoded request result or error
	 * @throws Exception
	 */
	public function requestIAM(string $endPoint, array $params = [], string $method = 'GET'): array {
		try {
			$serviceUrl = 'https://iam.cloud.ibm.com';

			$url = rtrim($serviceUrl, '/') . $endPoint;
			$options = [
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent' => Application::USER_AGENT,
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
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
		} catch (ClientException|ServerException $e) {
			$responseBody = $e->getResponse()->getBody();
			$parsedResponseBody = json_decode($responseBody, true);
			if ($e->getResponse()->getStatusCode() === 404) {
				$this->logger->debug('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			} else {
				$this->logger->warning('API request error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			}
			if (isset($parsedResponseBody['error']) && isset($parsedResponseBody['error']['message'])) {
				throw new Exception($this->l10n->t('API request error: ') . $parsedResponseBody['error']['message'], intval($e->getCode()));
			} else {
				throw new Exception($this->l10n->t('API request error: ') . $e->getMessage(), intval($e->getCode()));
			}
		}
	}
}
