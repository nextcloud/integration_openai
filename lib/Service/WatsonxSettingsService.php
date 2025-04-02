<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\Service;

use Exception;
use OCA\Watsonx\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

class WatsonxSettingsService {
	private const ADMIN_CONFIG_TYPES = [
		'request_timeout' => 'integer',
		'url' => 'string',
		'service_name' => 'string',
		'api_key' => 'string',
		'default_completion_model_id' => 'string',
		'chunk_size' => 'integer',
		'max_tokens' => 'integer',
		'use_max_completion_tokens_param' => 'boolean',
		'llm_extra_params' => 'string',
		'quota_period' => 'integer',
		'quotas' => 'array',
		'llm_provider_enabled' => 'boolean',
		'chat_endpoint_enabled' => 'boolean',
		'use_basic_auth' => 'boolean'
	];

	private const USER_CONFIG_TYPES = [
		'api_key' => 'string',
	];


	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private ICacheFactory $cacheFactory,
	) {
	}

	public function invalidateModelsCache(): void {
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		$cache->remove(Application::MODELS_CACHE_KEY);
	}

	////////////////////////////////////////////
	//////////// Getters for settings //////////

	/**
	 * @return string
	 * @throws Exception
	 */
	public function getAdminApiKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'api_key');
	}

	/**
	 * SIC! Does not fall back on the admin api by default
	 * @param null|string $userId
	 * @param boolean $fallBackOnAdminValue
	 * @return string
	 * @throws Exception
	 */
	public function getUserApiKey(?string $userId, bool $fallBackOnAdminValue = false): string {
		$fallBackApiKey = $fallBackOnAdminValue ? $this->getAdminApiKey() : '';
		if ($userId === null) {
			return $fallBackApiKey;
		}
		$encryptedUserApiKey = $this->config->getUserValue($userId, Application::APP_ID, 'api_key');
		$userApiKey = $encryptedUserApiKey === '' ? '' : $this->crypto->decrypt($encryptedUserApiKey);
		return $userApiKey ?: $fallBackApiKey;
	}

	/**
	 * @return string
	 */
	public function getAdminDefaultCompletionModelId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;
	}

	/**
	 * @return string
	 */
	public function getServiceUrl(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'url');
	}

	/**
	 * @return string
	 */
	public function getServiceName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'service_name');
	}

	/**
	 * @return int
	 */
	public function getRequestTimeout(): int {
		return intval($this->appConfig->getValueString(Application::APP_ID, 'request_timeout', strval(Application::WATSONX_DEFAULT_REQUEST_TIMEOUT))) ?: Application::WATSONX_DEFAULT_REQUEST_TIMEOUT;
	}

	/**
	 * @return int
	 */
	public function getChunkSize(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'chunk_size', Application::DEFAULT_CHUNK_SIZE) ?: Application::DEFAULT_CHUNK_SIZE;
	}

	/**
	 * @return int
	 */
	public function getMaxTokens(): int {
		return intval($this->appConfig->getValueString(Application::APP_ID, 'max_tokens', strval(Application::DEFAULT_MAX_NUM_OF_TOKENS))) ?: Application::DEFAULT_MAX_NUM_OF_TOKENS;
	}

	/**
	 * @return string
	 */
	public function getLlmExtraParams(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'llm_extra_params');
	}

	/**
	 * @return int
	 */
	public function getQuotaPeriod(): int {
		return intval($this->appConfig->getValueString(Application::APP_ID, 'quota_period', strval(Application::DEFAULT_QUOTA_PERIOD))) ?: Application::DEFAULT_QUOTA_PERIOD;
	}

	/**
	 * @return int[]
	 */
	public function getQuotas(): array {
		$quotas = json_decode(
			$this->appConfig->getValueString(
				Application::APP_ID, 'quotas',
				json_encode(Application::DEFAULT_QUOTAS)
			) ?: json_encode(Application::DEFAULT_QUOTAS),
			true,
		);
		if ($quotas === null) {
			$quotas = Application::DEFAULT_QUOTAS;
		}
		// Make sure all quota types are set in the json encoded app value (in case new quota types are added in the future)
		if (count($quotas) !== count(Application::DEFAULT_QUOTAS)) {
			foreach (Application::DEFAULT_QUOTAS as $quotaType => $_) {
				if (!isset($quotas[$quotaType])) {
					$quotas[$quotaType] = Application::DEFAULT_QUOTAS[$quotaType];
				}
			}
			$this->appConfig->setValueString(Application::APP_ID, 'quotas', json_encode($quotas));
		}

		return $quotas;
	}

	/**
	 * @return boolean
	 */
	public function getChatEndpointEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'chat_endpoint_enabled', '0') === '1';
	}

	/**
	 * @return boolean
	 */
	public function getUseBasicAuth(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'use_basic_auth', '0') === '1';
	}

	/**
	 * Get the admin config for the settings page
	 * @return mixed[]
	 */
	public function getAdminConfig(): array {
		return [
			'request_timeout' => $this->getRequestTimeout(),
			'url' => $this->getServiceUrl(),
			'service_name' => $this->getServiceName(),
			'api_key' => $this->getAdminApiKey(),
			'default_completion_model_id' => $this->getAdminDefaultCompletionModelId(),
			'chunk_size' => strval($this->getChunkSize()),
			'max_tokens' => $this->getMaxTokens(),
			'use_max_completion_tokens_param' => $this->getUseMaxCompletionTokensParam(),
			'llm_extra_params' => $this->getLlmExtraParams(),
			// Updated to get max tokens
			'quota_period' => $this->getQuotaPeriod(),
			// Updated to get quota period
			'quotas' => $this->getQuotas(),
			// Get quotas from the config value and return it
			'llm_provider_enabled' => $this->getLlmProviderEnabled(),
			'chat_endpoint_enabled' => $this->getChatEndpointEnabled(),
			'use_basic_auth' => $this->getUseBasicAuth()
		];
	}

	/**
	 * Get the user config for the settings page
	 * @return array{api_key: string, is_custom_service: bool, use_basic_auth: bool}
	 */
	public function getUserConfig(string $userId): array {
		$isCustomService = $this->getServiceUrl() !== '' && $this->getServiceUrl() !== Application::WATSONX_API_BASE_URL;
		return [
			'api_key' => $this->getUserApiKey($userId),
			'use_basic_auth' => $this->getUseBasicAuth(),
			'is_custom_service' => $isCustomService,

		];
	}

	/**
	 * @return bool
	 */
	public function getUseMaxCompletionTokensParam(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'use_max_completion_tokens_param', '0') === '1';
	}

	/**
	 * @return bool
	 */
	public function getLlmProviderEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'llm_provider_enabled', '1') === '1';
	}

	////////////////////////////////////////////
	//////////// Setters for settings //////////

	/**
	 * @param int[] $quotas
	 * @return void
	 * @throws Exception
	 */
	public function setQuotas(array $quotas): void {
		// Validate input
		if (count($quotas) !== count(Application::DEFAULT_QUOTAS)) {
			throw new Exception('Invalid number of quotas');
		}

		foreach ($quotas as $quotaType => $quota) {
			if (!isset(Application::DEFAULT_QUOTAS[$quotaType])) {
				throw new Exception('Invalid quota type(s)');
			}

			if (!is_int($quota) || $quota < 0) {
				throw new Exception('Invalid quota value');
			}
		}

		$this->appConfig->setValueString(Application::APP_ID, 'quotas', json_encode($quotas, JSON_THROW_ON_ERROR));
	}

	/**
	 * @param string $apiKey
	 * @return void
	 */
	public function setAdminApiKey(string $apiKey): void {
		// No need to validate. As long as it's a string, we're happy campers
		$this->appConfig->setValueString(Application::APP_ID, 'api_key', $apiKey, false, true);
		$this->invalidateModelsCache();
	}

	/**
	 * @param string $userId
	 * @param string $apiKey
	 * @throws PreConditionNotMetException
	 */
	public function setUserApiKey(string $userId, string $apiKey): void {
		// No need to validate. As long as it's a string, we're happy campers
		if ($apiKey === '') {
			$this->config->setUserValue($userId, Application::APP_ID, 'api_key', '');
		} else {
			$encryptedApiKey = $this->crypto->encrypt($apiKey);
			$this->config->setUserValue($userId, Application::APP_ID, 'api_key', $encryptedApiKey);
		}
		$this->invalidateModelsCache();
	}

	/**
	 * @param string $defaultCompletionModelId
	 * @return void
	 */
	public function setAdminDefaultCompletionModelId(string $defaultCompletionModelId): void {
		// No need to validate. As long as it's a string, we're happy campers
		$this->appConfig->setValueString(Application::APP_ID, 'default_completion_model_id', $defaultCompletionModelId);
	}

	/**
	 * @param string $serviceUrl
	 * @return void
	 * @throws Exception
	 */
	public function setServiceUrl(string $serviceUrl): void {
		// Validate input:
		if (!filter_var($serviceUrl, FILTER_VALIDATE_URL) && $serviceUrl !== '') {
			throw new Exception('Invalid service URL');
		}
		$this->appConfig->setValueString(Application::APP_ID, 'url', $serviceUrl);
		$this->invalidateModelsCache();
	}

	/**
	 * @param string $serviceName
	 * @return void
	 * @throws Exception
	 */
	public function setServiceName(string $serviceName): void {
		$this->appConfig->setValueString(Application::APP_ID, 'service_name', $serviceName);
	}

	/**
	 * @param int $requestTimeout
	 * @return void
	 */
	public function setRequestTimeout(int $requestTimeout): void {
		// Validate input:
		$requestTimeout = max(1, $requestTimeout);
		$this->appConfig->setValueString(Application::APP_ID, 'request_timeout', strval($requestTimeout));
	}

	/**
	 * Setter for chunkSize; default/minimum is 0 (no chunking)
	 * @param int $chunkSize
	 * @return void
	 */
	public function setChunkSize(int $chunkSize): void {
		// Validate input:
		$chunkSize = max(0, $chunkSize);
		if ($chunkSize) {
			$chunkSize = max(Application::MIN_CHUNK_SIZE, $chunkSize);
		}
		$this->appConfig->setValueInt(Application::APP_ID, 'chunk_size', $chunkSize);
	}

	/**
	 * Setter for maxTokens; minimum is 100
	 * @param int $maxTokens
	 * @return void
	 */
	public function setMaxTokens(int $maxTokens): void {
		// Validate input:
		$maxTokens = max(100, $maxTokens);
		$this->appConfig->setValueString(Application::APP_ID, 'max_tokens', strval($maxTokens));
	}

	public function setLlmExtraParams(string $llmExtraParams): void {
		if ($llmExtraParams !== '') {
			$paramsArray = json_decode($llmExtraParams, true);
			if (!is_array($paramsArray)) {
				throw new Exception('Invalid model extra parameters, must be a valid JSON object string or an empty string');
			}
		}
		$this->appConfig->setValueString(Application::APP_ID, 'llm_extra_params', $llmExtraParams);
	}

	/**
	 * Setter for quotaPeriod; minimum is 1 day
	 * @param int $quotaPeriod
	 * @return void
	 */
	public function setQuotaPeriod(int $quotaPeriod): void {
		// Validate input:
		$quotaPeriod = max(1, $quotaPeriod);
		$this->appConfig->setValueString(Application::APP_ID, 'quota_period', strval($quotaPeriod));
	}

	/**
	 * @param bool $useBasicAuth
	 * @return void
	 */
	public function setUseBasicAuth(bool $useBasicAuth): void {
		$this->appConfig->setValueString(Application::APP_ID, 'use_basic_auth', $useBasicAuth ? '1' : '0');
		$this->invalidateModelsCache();
	}

	/**
	 * Set the admin config for the settings page
	 * @param mixed[] $config
	 * @return void
	 * @throws Exception
	 */
	public function setAdminConfig(array $adminConfig): void {
		// That the variable types are correct
		foreach (array_keys($adminConfig) as $key) {
			$value = $adminConfig[$key];
			if ($value === null) {
				$this->config->deleteAppValue(Application::APP_ID, $key);
			} elseif (gettype($value) !== self::ADMIN_CONFIG_TYPES[$key]) {
				throw new Exception('Invalid type for key: ' . $key . '. Expected ' . self::ADMIN_CONFIG_TYPES[$key] . ', got ' . gettype($value));
			}
		}

		// Validation of the input values is done in the individual setters
		if (isset($adminConfig['request_timeout'])) {
			$this->setRequestTimeout($adminConfig['request_timeout']);
		}
		if (isset($adminConfig['url'])) {
			if (str_ends_with($adminConfig['url'], '/')) {
				$adminConfig['url'] = substr($adminConfig['url'], 0, -1) ?: $adminConfig['url'];
			}
			$this->setServiceUrl($adminConfig['url']);
		}
		if (isset($adminConfig['service_name'])) {
			$this->setServiceName($adminConfig['service_name']);
		}
		if (isset($adminConfig['api_key'])) {
			$this->setAdminApiKey($adminConfig['api_key']);
		}
		if (isset($adminConfig['default_completion_model_id'])) {
			$this->setAdminDefaultCompletionModelId($adminConfig['default_completion_model_id']);
		}
		if (isset($adminConfig['chunk_size'])) {
			$this->setChunkSize(intval($adminConfig['chunk_size']));
		}
		if (isset($adminConfig['max_tokens'])) {
			$this->setMaxTokens($adminConfig['max_tokens']);
		}
		if (isset($adminConfig['llm_extra_params'])) {
			$this->setLlmExtraParams($adminConfig['llm_extra_params']);
		}
		if (isset($adminConfig['quota_period'])) {
			$this->setQuotaPeriod($adminConfig['quota_period']);
		}
		if (isset($adminConfig['quotas'])) {
			$this->setQuotas($adminConfig['quotas']);
		}
		if (isset($adminConfig['use_max_completion_tokens_param'])) {
			$this->setUseMaxCompletionParam($adminConfig['use_max_completion_tokens_param']);
		}
		if (isset($adminConfig['llm_provider_enabled'])) {
			$this->setLlmProviderEnabled($adminConfig['llm_provider_enabled']);
		}
		if (isset($adminConfig['chat_endpoint_enabled'])) {
			$this->setChatEndpointEnabled($adminConfig['chat_endpoint_enabled']);
		}
		if (isset($adminConfig['use_basic_auth'])) {
			$this->setUseBasicAuth($adminConfig['use_basic_auth']);
		}
	}

	/**
	 * Set the user config for the settings page
	 * @param string $userId
	 * @param string[] $userConfig
	 */
	public function setUserConfig(string $userId, array $userConfig): void {
		// That the variable types are correct
		foreach (array_keys($userConfig) as $key) {
			if (gettype($userConfig[$key]) !== self::USER_CONFIG_TYPES[$key]) {
				throw new Exception('Invalid type for key: ' . $key . '. Expected ' . self::ADMIN_CONFIG_TYPES[$key] . ', got ' . gettype($userConfig[$key]));
			}
		}

		// Validation of the input values is done in the individual setters
		if (isset($userConfig['api_key'])) {
			$this->setUserApiKey($userId, $userConfig['api_key']);
		}
	}

	/**
	 * @param bool $enabled
	 * @return void
	 */
	public function setUseMaxCompletionParam(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'use_max_completion_tokens_param', $enabled ? '1' : '0');
	}

	/**
	 * @param bool $enabled
	 * @return void
	 */
	public function setLlmProviderEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'llm_provider_enabled', $enabled ? '1' : '0');
	}

	/**
	 * @param bool $enabled
	 */
	public function setChatEndpointEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'chat_endpoint_enabled', $enabled ? '1' : '0');
	}
}
