<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use DateInterval;
use DateTime;
use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\PreConditionNotMetException;
use OCP\Security\ICrypto;

class OpenAiSettingsService {
	private const ADMIN_CONFIG_TYPES = [
		'request_timeout' => 'integer',
		'url' => 'string',
		'service_name' => 'string',
		'api_key' => 'string',
		'default_completion_model_id' => 'string',
		'default_stt_model_id' => 'string',
		'default_tts_model_id' => 'string',
		'tts_voices' => 'array',
		'default_tts_voice' => 'string',
		'default_image_model_id' => 'string',
		'default_image_size' => 'string',
		'image_request_auth' => 'boolean',
		'chunk_size' => 'integer',
		'max_tokens' => 'integer',
		'use_max_completion_tokens_param' => 'boolean',
		'llm_extra_params' => 'string',
		'quota_period' => 'array',
		'quotas' => 'array',
		'usage_storage_time' => 'integer',
		'translation_provider_enabled' => 'boolean',
		'llm_provider_enabled' => 'boolean',
		't2i_provider_enabled' => 'boolean',
		'stt_provider_enabled' => 'boolean',
		'tts_provider_enabled' => 'boolean',
		'analyze_image_provider_enabled' => 'boolean',
		'chat_endpoint_enabled' => 'boolean',
		'basic_user' => 'string',
		'basic_password' => 'string',
		'use_basic_auth' => 'boolean',

		'image_url' => 'string',
		'image_service_name' => 'string',
		'image_api_key' => 'string',
		'image_basic_user' => 'string',
		'image_basic_password' => 'string',
		'image_use_basic_auth' => 'boolean',
		'image_request_timeout' => 'integer',

		'stt_url' => 'string',
		'stt_service_name' => 'string',
		'stt_api_key' => 'string',
		'stt_basic_user' => 'string',
		'stt_basic_password' => 'string',
		'stt_use_basic_auth' => 'boolean',
		'stt_request_timeout' => 'integer',

		'tts_url' => 'string',
		'tts_service_name' => 'string',
		'tts_api_key' => 'string',
		'tts_basic_user' => 'string',
		'tts_basic_password' => 'string',
		'tts_use_basic_auth' => 'boolean',
		'tts_request_timeout' => 'integer',
	];

	private const USER_CONFIG_TYPES = [
		'api_key' => 'string',
		'basic_user' => 'string',
		'basic_password' => 'string',
		'stt_language' => 'string',
	];


	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private ICacheFactory $cacheFactory,
	) {
	}

	/**
	 * Gets the timestamp of the beginning of the quota period
	 *
	 * @return int
	 * @throws Exception
	 */
	public function getQuotaStart(): int {
		$quotaPeriod = $this->getQuotaPeriod();
		$now = new DateTime();

		if ($quotaPeriod['unit'] === 'day') {
			// Get a timestamp of the beginning of the time period
			$periodStart = $now->sub(new DateInterval('P' . $quotaPeriod['length'] . 'D'));
		} else {
			$periodStart = new DateTime(date('Y-m-' . $quotaPeriod['day']));
			// Ensure that this isn't in the future
			if ($periodStart > $now) {
				$periodStart = $periodStart->sub(new DateInterval('P1M'));
			}
			if ($quotaPeriod['length'] > 1) {
				// Calculate number of months since 2000-01 to ensure the start month is consistent
				$startDate = new DateTime('2000-01-' . $quotaPeriod['day']);
				$months = $startDate->diff($periodStart)->m + $startDate->diff($periodStart)->y * 12;
				$remainder = $months % $quotaPeriod['length'];
				$periodStart = $periodStart->sub(new DateInterval('P' . $remainder . 'M'));
			}
		}
		return $periodStart->getTimestamp();
	}

	/**
	 * Gets the timestamp of the end of the quota period
	 * if the period is floating, then this will be the current time
	 *
	 * @return int
	 * @throws Exception
	 */
	public function getQuotaEnd(): int {
		$quotaPeriod = $this->getQuotaPeriod();
		$now = new DateTime();

		if ($quotaPeriod['unit'] === 'day') {
			// Get a timestamp of the beginning of the time period
			$periodEnd = $now;
		} else {
			$periodEnd = new DateTime(date('Y-m-' . $quotaPeriod['day']));
			// Ensure that this isn't in the past
			if ($periodEnd < $now) {
				$periodEnd = $periodEnd->add(new DateInterval('P1M'));
			}
			if ($quotaPeriod['length'] > 1) {
				// Calculate number of months since 2000-01 to ensure the start month is consistent
				$startDate = new DateTime('2000-01-' . $quotaPeriod['day']);
				$months = $startDate->diff($periodEnd)->m + $startDate->diff($periodEnd)->y * 12;
				$remainder = $months % $quotaPeriod['length'];
				if ($remainder != 0) {
					$periodEnd = $periodEnd->add(new DateInterval('P' . $quotaPeriod['length'] - $remainder . 'M'));
				}
			}
		}
		return $periodEnd->getTimestamp();
	}

	public function invalidateModelsCache(): void {
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		$cache->clear(Application::MODELS_CACHE_KEY);
	}

	////////////////////////////////////////////
	//////////// Getters for settings //////////

	/**
	 * @return string
	 * @throws Exception
	 */
	public function getAdminApiKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'api_key', lazy: true);
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
	 * @param string|null $userId
	 * @return string
	 */
	public function getUserSTTLanguage(?string $userId): string {
		return $this->config->getUserValue($userId, Application::APP_ID, 'stt_language', 'detect_language');
	}

	/**
	 * @return string
	 */
	public function getAdminDefaultCompletionModelId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID, lazy: true) ?: Application::DEFAULT_COMPLETION_MODEL_ID;
	}

	/**
	 * @return string
	 */
	public function getAdminDefaultSttModelId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'default_stt_model_id', lazy: true) ?: Application::DEFAULT_MODEL_ID;
	}

	/**
	 * @return string
	 */
	public function getAdminDefaultImageModelId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'default_image_model_id', lazy: true) ?: Application::DEFAULT_MODEL_ID;
	}

	/**
	 * @return string
	 */
	public function getAdminDefaultImageSize(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'default_image_size', lazy: true) ?: Application::DEFAULT_DEFAULT_IMAGE_SIZE;
	}

	/**
	 * @return string
	 */
	public function getAdminDefaultTtsModelId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'default_speech_model_id', lazy: true) ?: Application::DEFAULT_MODEL_ID;
	}

	/**
	 * @return string
	 */
	public function getAdminDefaultTtsVoice(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'default_speech_voice', lazy: true) ?: Application::DEFAULT_SPEECH_VOICE;
	}

	/**
	 * @return array
	 */
	public function getAdminTtsVoices(): array {
		$voices = json_decode(
			$this->appConfig->getValueString(
				Application::APP_ID, 'tts_voices',
				json_encode(Application::DEFAULT_SPEECH_VOICES),
				lazy: true,
			) ?: json_encode(Application::DEFAULT_SPEECH_VOICES),
			true,
		);
		if (!is_array($voices)) {
			$voices = Application::DEFAULT_SPEECH_VOICES;
		}
		return $voices;
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
		return $this->appConfig->getValueString(Application::APP_ID, 'service_name', lazy: true);
	}

	/**
	 * @return int
	 */
	public function getRequestTimeout(): int {
		return intval($this->appConfig->getValueString(Application::APP_ID, 'request_timeout', strval(Application::OPENAI_DEFAULT_REQUEST_TIMEOUT), lazy: true)) ?: Application::OPENAI_DEFAULT_REQUEST_TIMEOUT;
	}

	/**
	 * @return int
	 */
	public function getChunkSize(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'chunk_size', Application::DEFAULT_CHUNK_SIZE, lazy: true) ?: Application::DEFAULT_CHUNK_SIZE;
	}

	/**
	 * @return int
	 */
	public function getMaxTokens(): int {
		return intval($this->appConfig->getValueString(Application::APP_ID, 'max_tokens', strval(Application::DEFAULT_MAX_NUM_OF_TOKENS), lazy: true)) ?: Application::DEFAULT_MAX_NUM_OF_TOKENS;
	}

	/**
	 * @return string
	 */
	public function getLlmExtraParams(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'llm_extra_params', lazy: true);
	}

	/**
	 * @return array
	 */
	public function getQuotaPeriod(): array {
		$value = json_decode(
			$this->appConfig->getValueString(Application::APP_ID, 'quota_period', json_encode(Application::DEFAULT_QUOTA_CONFIG), lazy: true),
			true
		) ?: Application::DEFAULT_QUOTA_CONFIG;
		// Migrate from old quota period to new one
		if (is_int($value)) {
			$value = ['length' => $value];
		}
		foreach (Application::DEFAULT_QUOTA_CONFIG as $key => $defaultValue) {
			if (!isset($value[$key])) {
				$value[$key] = $defaultValue;
			}
		}
		return $value;
	}

	/**
	 * @return int[]
	 */
	public function getQuotas(): array {
		$quotas = json_decode(
			$this->appConfig->getValueString(
				Application::APP_ID, 'quotas',
				json_encode(Application::DEFAULT_QUOTAS),
				lazy: true,
			) ?: json_encode(Application::DEFAULT_QUOTAS),
			true,
		);
		if ($quotas === null) {
			$quotas = Application::DEFAULT_QUOTAS;
		}
		// Make sure all quota types are set in the json encoded app value (in case new quota types are added in the future)
		if (count($quotas) !== count(Application::DEFAULT_QUOTAS)) {
			foreach (Application::DEFAULT_QUOTAS as $quotaType => $_) {
				if (!isset($quotas[$quotaType]) || !is_int($quotas[$quotaType]) || $quotas[$quotaType] < 0) {
					$quotas[$quotaType] = Application::DEFAULT_QUOTAS[$quotaType];
				}
			}
			$this->appConfig->setValueString(Application::APP_ID, 'quotas', json_encode($quotas), lazy: true);
		}

		return $quotas;
	}

	public function getUsageStorageTime() : int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'usage_storage_time', Application::DEFAULT_QUOTA_PERIOD, lazy: true);
	}

	/**
	 * @return boolean
	 */
	public function getChatEndpointEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'chat_endpoint_enabled', '1', lazy: true) === '1';
	}

	/**
	 * @param string|null $userId
	 * @param bool $fallBackOnAdminValue
	 * @return string
	 */
	public function getUserBasicUser(?string $userId, bool $fallBackOnAdminValue = true): string {
		$fallBackBasicUser = $fallBackOnAdminValue ? $this->getAdminBasicUser() : '';
		$basicUser = $userId === null
			? $fallBackBasicUser
			: ($this->config->getUserValue($userId, Application::APP_ID, 'basic_user', $fallBackBasicUser) ?: $fallBackBasicUser);
		return $basicUser;
	}

	/**
	 * @param string|null $userId
	 * @param bool $fallBackOnAdminValue
	 * @return string
	 * @throws Exception
	 */
	public function getUserBasicPassword(?string $userId, bool $fallBackOnAdminValue = true): string {
		$fallBackBasicPassword = $fallBackOnAdminValue ? $this->getAdminBasicPassword() : '';
		if ($userId === null) {
			return $fallBackBasicPassword;
		}
		$encryptedUserBasicPassword = $this->config->getUserValue($userId, Application::APP_ID, 'basic_password');
		$userBasicPassword = $encryptedUserBasicPassword === '' ? '' : $this->crypto->decrypt($encryptedUserBasicPassword);
		return $userBasicPassword ?: $fallBackBasicPassword;
	}

	/**
	 * Get admin basic user
	 * @return string
	 */
	public function getAdminBasicUser(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'basic_user', lazy: true);
	}

	/**
	 * Get admin basic password
	 * @return string
	 * @throws Exception
	 */
	public function getAdminBasicPassword(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'basic_password', lazy: true);
	}

	/**
	 * @return boolean
	 */
	public function getUseBasicAuth(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'use_basic_auth', '0', lazy: true) === '1';
	}

	/**
	 * @return string
	 */
	public function getImageUrl(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'image_url', '', lazy: true);
	}

	/**
	 * @return string
	 */
	public function getImageServiceName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'image_service_name', '', lazy: true);
	}

	/**
	 * @return string
	 */
	public function getAdminImageApiKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'image_api_key', '', true);
	}

	/**
	 * @return string
	 */
	public function getAdminImageBasicUser(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'image_basic_user', '', lazy: true);
	}

	/**
	 * @return string
	 */
	public function getAdminImageBasicPassword(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'image_basic_password', '', true);
	}

	/**
	 * @return bool
	 */
	public function getAdminImageUseBasicAuth(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'image_use_basic_auth', '0', lazy: true) === '1';
	}

	/**
	 * @return int
	 */
	public function getImageRequestTimeout(): int {
		return intval($this->appConfig->getValueString(Application::APP_ID, 'image_request_timeout', strval(Application::OPENAI_DEFAULT_REQUEST_TIMEOUT), lazy: true)) ?: Application::OPENAI_DEFAULT_REQUEST_TIMEOUT;
	}

	/**
	 * @return string
	 */
	public function getSttUrl(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'stt_url', '', lazy: true);
	}

	/**
	 * @return string
	 */
	public function getSttServiceName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'stt_service_name', '', lazy: true);
	}

	/**
	 * @return string
	 */
	public function getAdminSttApiKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'stt_api_key', '', true);
	}

	/**
	 * @return string
	 */
	public function getAdminSttBasicUser(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'stt_basic_user', '', lazy: true);
	}

	/**
	 * @return string
	 */
	public function getAdminSttBasicPassword(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'stt_basic_password', '', true);
	}

	/**
	 * @return bool
	 */
	public function getAdminSttUseBasicAuth(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'stt_use_basic_auth', '0', lazy: true) === '1';
	}

	/**
	 * @return int
	 */
	public function getSttRequestTimeout(): int {
		return intval($this->appConfig->getValueString(Application::APP_ID, 'stt_request_timeout', strval(Application::OPENAI_DEFAULT_REQUEST_TIMEOUT), lazy: true)) ?: Application::OPENAI_DEFAULT_REQUEST_TIMEOUT;
	}

	/**
	 * @return string
	 */
	public function getTtsUrl(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'tts_url', '', lazy: true);
	}

	/**
	 * @return string
	 */
	public function getTtsServiceName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'tts_service_name', '', lazy: true);
	}

	/**
	 * @return string
	 */
	public function getAdminTtsApiKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'tts_api_key', '', true);
	}

	/**
	 * @return string
	 */
	public function getAdminTtsBasicUser(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'tts_basic_user', '', lazy: true);
	}

	/**
	 * @return string
	 */
	public function getAdminTtsBasicPassword(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'tts_basic_password', '', true);
	}

	/**
	 * @return bool
	 */
	public function getAdminTtsUseBasicAuth(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'tts_use_basic_auth', '0', lazy: true) === '1';
	}

	/**
	 * @return int
	 */
	public function getTtsRequestTimeout(): int {
		return intval($this->appConfig->getValueString(Application::APP_ID, 'tts_request_timeout', strval(Application::OPENAI_DEFAULT_REQUEST_TIMEOUT), lazy: true)) ?: Application::OPENAI_DEFAULT_REQUEST_TIMEOUT;
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
			'default_stt_model_id' => $this->getAdminDefaultSttModelId(),
			'default_tts_model_id' => $this->getAdminDefaultTtsModelId(),
			'default_tts_voice' => $this->getAdminDefaultTtsVoice(),
			'tts_voices' => $this->getAdminTtsVoices(),
			'default_image_model_id' => $this->getAdminDefaultImageModelId(),
			'default_image_size' => $this->getAdminDefaultImageSize(),
			'image_request_auth' => $this->getIsImageRetrievalAuthenticated(),
			'chunk_size' => strval($this->getChunkSize()),
			'max_tokens' => $this->getMaxTokens(),
			'use_max_completion_tokens_param' => $this->getUseMaxCompletionTokensParam(),
			'llm_extra_params' => $this->getLlmExtraParams(),
			// Updated to get max tokens
			'quota_period' => $this->getQuotaPeriod(),
			// Updated to get quota period
			'quotas' => $this->getQuotas(),
			// Get quotas from the config value and return it
			'usage_storage_time' => $this->getUsageStorageTime(),
			'translation_provider_enabled' => $this->getTranslationProviderEnabled(),
			'llm_provider_enabled' => $this->getLlmProviderEnabled(),
			't2i_provider_enabled' => $this->getT2iProviderEnabled(),
			'stt_provider_enabled' => $this->getSttProviderEnabled(),
			'tts_provider_enabled' => $this->getTtsProviderEnabled(),
			'analyze_image_provider_enabled' => $this->getAnalyzeImageProviderEnabled(),
			'chat_endpoint_enabled' => $this->getChatEndpointEnabled(),
			'basic_user' => $this->getAdminBasicUser(),
			'basic_password' => $this->getAdminBasicPassword(),
			'use_basic_auth' => $this->getUseBasicAuth(),
			// Get the service details for image, stt and tts
			'image_url' => $this->getImageUrl(),
			'image_service_name' => $this->getImageServiceName(),
			'image_api_key' => $this->getAdminImageApiKey(),
			'image_basic_user' => $this->getAdminImageBasicUser(),
			'image_basic_password' => $this->getAdminImageBasicPassword(),
			'image_use_basic_auth' => $this->getAdminImageUseBasicAuth(),
			'image_request_timeout' => $this->getImageRequestTimeout(),

			'stt_url' => $this->getSttUrl(),
			'stt_service_name' => $this->getSttServiceName(),
			'stt_api_key' => $this->getAdminSttApiKey(),
			'stt_basic_user' => $this->getAdminSttBasicUser(),
			'stt_basic_password' => $this->getAdminSttBasicPassword(),
			'stt_use_basic_auth' => $this->getAdminSttUseBasicAuth(),
			'stt_request_timeout' => $this->getSttRequestTimeout(),

			'tts_url' => $this->getTtsUrl(),
			'tts_service_name' => $this->getTtsServiceName(),
			'tts_api_key' => $this->getAdminTtsApiKey(),
			'tts_basic_user' => $this->getAdminTtsBasicUser(),
			'tts_basic_password' => $this->getAdminTtsBasicPassword(),
			'tts_use_basic_auth' => $this->getAdminTtsUseBasicAuth(),
			'tts_request_timeout' => $this->getTtsRequestTimeout(),
		];
	}

	/**
	 * Get the user config for the settings page
	 * @return array{api_key: string, basic_password: string, basic_user: string, is_custom_service: bool, use_basic_auth: bool, stt_language: string}
	 */
	public function getUserConfig(string $userId): array {
		$isCustomService = $this->getServiceUrl() !== '' && $this->getServiceUrl() !== Application::OPENAI_API_BASE_URL;
		return [
			'api_key' => $this->getUserApiKey($userId),
			'basic_user' => $this->getUserBasicUser($userId, false),
			'basic_password' => $this->getUserBasicPassword($userId, false),
			'use_basic_auth' => $this->getUseBasicAuth(),
			'is_custom_service' => $isCustomService,
			'stt_language' => $this->getUserSTTLanguage($userId)

		];
	}

	/**
	 * @return bool
	 */
	public function getUseMaxCompletionTokensParam(): bool {
		$serviceUrl = $this->getServiceUrl();
		$isUsingOpenAI = $serviceUrl === '' || $serviceUrl === Application::OPENAI_API_BASE_URL;
		// we know OpenAI expects "use_max_completion_tokens_param", let's assume the other services don't
		$default = $isUsingOpenAI ? '1' : '0';
		return $this->appConfig->getValueString(Application::APP_ID, 'use_max_completion_tokens_param', $default, lazy: true) === '1';
	}

	/**
	 * @return bool
	 */
	public function getTranslationProviderEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'translation_provider_enabled', '1') === '1';
	}

	/**
	 * @return bool
	 */
	public function getIsImageRetrievalAuthenticated(): bool {
		$serviceUrl = $this->getServiceUrl();
		$isUsingOpenAI = $serviceUrl === '' || $serviceUrl === Application::OPENAI_API_BASE_URL;
		$default = $isUsingOpenAI ? '0' : '1';
		return $this->appConfig->getValueString(Application::APP_ID, 'image_request_auth', $default, lazy: true) === '1';
	}

	/**
	 * @return bool
	 */
	public function getLlmProviderEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'llm_provider_enabled', '1') === '1';
	}

	/**
	 * @return bool
	 */
	public function getT2iProviderEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 't2i_provider_enabled', '1') === '1';
	}

	/**
	 * @return bool
	 */
	public function getSttProviderEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'stt_provider_enabled', '1') === '1';
	}

	/**
	 * @return bool
	 */
	public function getTtsProviderEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'tts_provider_enabled', '1') === '1';
	}

	/**
	 * @return bool
	 */
	public function getAnalyzeImageProviderEnabled(): bool {
		$config = $this->appConfig->getValueString(Application::APP_ID, 'analyze_image_provider_enabled');
		if ($config === '') {
			$serviceUrl = $this->getServiceUrl();
			$isUsingOpenAI = $serviceUrl === '' || $serviceUrl === Application::OPENAI_API_BASE_URL;
			return $isUsingOpenAI;
		}
		return $config === '1';
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

		$this->appConfig->setValueString(Application::APP_ID, 'quotas', json_encode($quotas, JSON_THROW_ON_ERROR), lazy: true);
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		$cache->clear(Application::QUOTA_RULES_CACHE_PREFIX);
	}

	/**
	 * @param int $usageStorageTime
	 * @return void
	 */
	public function setUsageStorageTime(int $usageStorageTime): void {
		$usageStorageTime = max(1, $usageStorageTime);
		$this->appConfig->setValueInt(Application::APP_ID, 'usage_storage_time', $usageStorageTime, lazy: true);
	}

	/**
	 * @param string $apiKey
	 * @return void
	 */
	public function setAdminApiKey(string $apiKey): void {
		// No need to validate. As long as it's a string, we're happy campers
		$this->appConfig->setValueString(Application::APP_ID, 'api_key', $apiKey, true, true);
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
	 * @param string $userId
	 * @param string $language
	 * @throws PreConditionNotMetException
	 */
	public function setUserSTTLanguage(string $userId, string $language): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'stt_language', $language);
	}

	/**
	 * @param string $defaultCompletionModelId
	 * @return void
	 */
	public function setAdminDefaultCompletionModelId(string $defaultCompletionModelId): void {
		// No need to validate. As long as it's a string, we're happy campers
		$this->appConfig->setValueString(Application::APP_ID, 'default_completion_model_id', $defaultCompletionModelId, lazy: true);
	}

	/**
	 * @param string $defaultSttModelId
	 * @return void
	 */
	public function setAdminDefaultSttModelId(string $defaultSttModelId): void {
		// No need to validate. As long as it's a string, we're happy campers
		$this->appConfig->setValueString(Application::APP_ID, 'default_stt_model_id', $defaultSttModelId, lazy: true);
	}

	/**
	 * @param string $defaultTtsModelId
	 * @return void
	 */
	public function setAdminDefaultTtsModelId(string $defaultTtsModelId): void {
		// No need to validate. As long as it's a string, we're happy campers
		$this->appConfig->setValueString(Application::APP_ID, 'default_speech_model_id', $defaultTtsModelId, lazy: true);
	}

	/**
	 * @param string $defaultImageModelId
	 * @return void
	 */
	public function setAdminDefaultImageModelId(string $defaultImageModelId): void {
		// No need to validate. As long as it's a string, we're happy campers
		$this->appConfig->setValueString(Application::APP_ID, 'default_image_model_id', $defaultImageModelId, lazy: true);
	}

	/**
	 * @param string $voice
	 * @return void
	 */
	public function setAdminDefaultTtsVoice(string $voice): void {
		$this->appConfig->setValueString(Application::APP_ID, 'default_speech_voice', $voice, lazy: true);
	}

	/**
	 * @param string $defaultImageSize
	 * @return void
	 * @throws Exception
	 */
	public function setAdminDefaultImageSize(string $defaultImageSize): void {
		if ($defaultImageSize !== '' && preg_match('/^\d+x\d+$/', $defaultImageSize) !== 1) {
			throw new Exception('Invalid image size value');
		}
		$this->appConfig->setValueString(Application::APP_ID, 'default_image_size', $defaultImageSize, lazy: true);
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
		$this->appConfig->setValueString(Application::APP_ID, 'service_name', $serviceName, lazy: true);
	}

	/**
	 * @param int $requestTimeout
	 * @return void
	 */
	public function setRequestTimeout(int $requestTimeout): void {
		// Validate input:
		$requestTimeout = max(1, $requestTimeout);
		$this->appConfig->setValueString(Application::APP_ID, 'request_timeout', strval($requestTimeout), lazy: true);
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
		$this->appConfig->setValueInt(Application::APP_ID, 'chunk_size', $chunkSize, lazy: true);
	}

	/**
	 * Setter for maxTokens; minimum is 100
	 * @param int $maxTokens
	 * @return void
	 */
	public function setMaxTokens(int $maxTokens): void {
		// Validate input:
		$maxTokens = max(100, $maxTokens);
		$this->appConfig->setValueString(Application::APP_ID, 'max_tokens', strval($maxTokens), lazy: true);
	}

	public function setLlmExtraParams(string $llmExtraParams): void {
		if ($llmExtraParams !== '') {
			$paramsArray = json_decode($llmExtraParams, true);
			if (!is_array($paramsArray)) {
				throw new Exception('Invalid model extra parameters, must be a valid JSON object string or an empty string');
			}
		}
		$this->appConfig->setValueString(Application::APP_ID, 'llm_extra_params', $llmExtraParams, lazy: true);
	}

	/**
	 * Setter for quotaPeriod; minimum is 1 day.
	 * Days are floating, and months are set dates
	 * @param array $quotaPeriod
	 * @return void
	 * @throws Exception
	 */
	public function setQuotaPeriod(array $quotaPeriod): void {
		if (!isset($quotaPeriod['length']) || !is_int($quotaPeriod['length'])) {
			throw new Exception('Invalid quota period length');
		}
		if ($quotaPeriod['length'] < 1) {
			throw new Exception('Invalid quota period length');
		}
		if (!isset($quotaPeriod['unit']) || !is_string($quotaPeriod['unit'])) {
			throw new Exception('Invalid quota period unit');
		}
		// Checks month period
		if ($quotaPeriod['unit'] === 'month') {
			if (!isset($quotaPeriod['day']) || !is_int($quotaPeriod['day'])) {
				throw new Exception('Invalid quota period day');
			}
			if ($quotaPeriod['day'] < 1) {
				throw new Exception('Invalid quota period day');
			}
			if ($quotaPeriod['day'] > 28) {
				throw new Exception('Invalid quota period day');
			}
		} elseif ($quotaPeriod['unit'] !== 'day') {
			throw new Exception('Invalid quota period unit');
		}
		$this->appConfig->setValueString(Application::APP_ID, 'quota_period', json_encode($quotaPeriod), lazy: true);
	}

	/**
	 * @param string $basicUser
	 * @return void
	 */
	public function setAdminBasicUser(string $basicUser): void {
		$this->appConfig->setValueString(Application::APP_ID, 'basic_user', $basicUser, lazy: true);
		$this->invalidateModelsCache();
	}

	/**
	 * @param string $basicPassword
	 * @return void
	 */
	public function setAdminBasicPassword(string $basicPassword): void {
		$this->appConfig->setValueString(Application::APP_ID, 'basic_password', $basicPassword, true, true);
		$this->invalidateModelsCache();
	}

	/**
	 * @param string $userId
	 * @param string $basicUser
	 * @return void
	 * @throws PreConditionNotMetException
	 */
	public function setUserBasicUser(string $userId, string $basicUser): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'basic_user', $basicUser);
		$this->invalidateModelsCache();
	}

	/**
	 * @param string $userId
	 * @param string $basicPassword
	 * @return void
	 * @throws PreConditionNotMetException
	 */
	public function setUserBasicPassword(string $userId, string $basicPassword): void {
		$encryptedBasicPassword = $basicPassword === '' ? '' : $this->crypto->encrypt($basicPassword);
		$this->config->setUserValue($userId, Application::APP_ID, 'basic_password', $encryptedBasicPassword);
		$this->invalidateModelsCache();
	}

	/**
	 * @param bool $useBasicAuth
	 * @return void
	 */
	public function setUseBasicAuth(bool $useBasicAuth): void {
		$this->appConfig->setValueString(Application::APP_ID, 'use_basic_auth', $useBasicAuth ? '1' : '0', lazy: true);
		$this->invalidateModelsCache();
	}

	/**
	 * @param array $voices
	 * @return void
	 */
	public function setAdminTtsVoices(array $voices): void {
		$this->appConfig->setValueString(Application::APP_ID, 'tts_voices', json_encode($voices), lazy: true);
		$this->invalidateModelsCache();
	}

	/**
	 * @param string $url
	 * @return void
	 * @throws Exception
	 */
	public function setImageUrl(string $url): void {
		if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
			throw new Exception('Invalid image service URL');
		}
		$this->appConfig->setValueString(Application::APP_ID, 'image_url', $url, lazy: true);
	}

	/**
	 * @param string $name
	 * @return void
	 */
	public function setImageServiceName(string $name): void {
		$this->appConfig->setValueString(Application::APP_ID, 'image_service_name', $name, lazy: true);
	}

	/**
	 * @param string $apiKey
	 * @return void
	 */
	public function setAdminImageApiKey(string $apiKey): void {
		$this->appConfig->setValueString(Application::APP_ID, 'image_api_key', $apiKey, true, true);
	}

	/**
	 * @param string $user
	 * @return void
	 */
	public function setAdminImageBasicUser(string $user): void {
		$this->appConfig->setValueString(Application::APP_ID, 'image_basic_user', $user, lazy: true);
	}

	/**
	 * @param string $password
	 * @return void
	 */
	public function setAdminImageBasicPassword(string $password): void {
		$this->appConfig->setValueString(Application::APP_ID, 'image_basic_password', $password, true, true);
	}

	/**
	 * @param bool $use
	 * @return void
	 */
	public function setAdminImageUseBasicAuth(bool $use): void {
		$this->appConfig->setValueString(Application::APP_ID, 'image_use_basic_auth', $use ? '1' : '0', lazy: true);
	}

	/**
	 * @param int $requestTimeout
	 * @return void
	 */
	public function setImageRequestTimeout(int $requestTimeout): void {
		// Validate input:
		$requestTimeout = max(1, $requestTimeout);
		$this->appConfig->setValueString(Application::APP_ID, 'image_request_timeout', strval($requestTimeout), lazy: true);
	}
	/**
	 * @param string $url
	 * @return void
	 * @throws Exception
	 */
	public function setSttUrl(string $url): void {
		if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
			throw new Exception('Invalid STT service URL');
		}
		$this->appConfig->setValueString(Application::APP_ID, 'stt_url', $url, lazy: true);
	}

	/**
	 * @param string $name
	 * @return void
	 */
	public function setSttServiceName(string $name): void {
		$this->appConfig->setValueString(Application::APP_ID, 'stt_service_name', $name, lazy: true);
	}

	/**
	 * @param string $apiKey
	 * @return void
	 */
	public function setAdminSttApiKey(string $apiKey): void {
		$this->appConfig->setValueString(Application::APP_ID, 'stt_api_key', $apiKey, true, true);
	}

	/**
	 * @param string $user
	 * @return void
	 */
	public function setAdminSttBasicUser(string $user): void {
		$this->appConfig->setValueString(Application::APP_ID, 'stt_basic_user', $user, lazy: true);
	}

	/**
	 * @param string $password
	 * @return void
	 */
	public function setAdminSttBasicPassword(string $password): void {
		$this->appConfig->setValueString(Application::APP_ID, 'stt_basic_password', $password, true, true);
	}

	/**
	 * @param bool $use
	 * @return void
	 */
	public function setAdminSttUseBasicAuth(bool $use): void {
		$this->appConfig->setValueString(Application::APP_ID, 'stt_use_basic_auth', $use ? '1' : '0', lazy: true);
	}

	/**
	 * @param int $requestTimeout
	 * @return void
	 */
	public function setSttRequestTimeout(int $requestTimeout): void {
		// Validate input:
		$requestTimeout = max(1, $requestTimeout);
		$this->appConfig->setValueString(Application::APP_ID, 'stt_request_timeout', strval($requestTimeout), lazy: true);
	}

	/**
	 * @param string $url
	 * @return void
	 * @throws Exception
	 */
	public function setTtsUrl(string $url): void {
		if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
			throw new Exception('Invalid TTS service URL');
		}
		$this->appConfig->setValueString(Application::APP_ID, 'tts_url', $url, lazy: true);
	}

	/**
	 * @param string $name
	 * @return void
	 */
	public function setTtsServiceName(string $name): void {
		$this->appConfig->setValueString(Application::APP_ID, 'tts_service_name', $name, lazy: true);
	}

	/**
	 * @param string $apiKey
	 * @return void
	 */
	public function setAdminTtsApiKey(string $apiKey): void {
		$this->appConfig->setValueString(Application::APP_ID, 'tts_api_key', $apiKey, true, true);
	}

	/**
	 * @param string $user
	 * @return void
	 */
	public function setAdminTtsBasicUser(string $user): void {
		$this->appConfig->setValueString(Application::APP_ID, 'tts_basic_user', $user, lazy: true);
	}

	/**
	 * @param string $password
	 * @return void
	 */
	public function setAdminTtsBasicPassword(string $password): void {
		$this->appConfig->setValueString(Application::APP_ID, 'tts_basic_password', $password, true, true);
	}

	/**
	 * @param bool $use
	 * @return void
	 */
	public function setAdminTtsUseBasicAuth(bool $use): void {
		$this->appConfig->setValueString(Application::APP_ID, 'tts_use_basic_auth', $use ? '1' : '0', lazy: true);
	}

	/**
	 * @param int $requestTimeout
	 * @return void
	 */
	public function setTtsRequestTimeout(int $requestTimeout): void {
		// Validate input:
		$requestTimeout = max(1, $requestTimeout);
		$this->appConfig->setValueString(Application::APP_ID, 'tts_request_timeout', strval($requestTimeout), lazy: true);
	}

	/**
	 * Set the admin config for the settings page
	 * @param mixed[] $adminConfig
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
		if (isset($adminConfig['default_stt_model_id'])) {
			$this->setAdminDefaultSttModelId($adminConfig['default_stt_model_id']);
		}
		if (isset($adminConfig['default_tts_model_id'])) {
			$this->setAdminDefaultTtsModelId($adminConfig['default_tts_model_id']);
		}
		if (isset($adminConfig['default_image_model_id'])) {
			$this->setAdminDefaultImageModelId($adminConfig['default_image_model_id']);
		}
		if (isset($adminConfig['default_image_size'])) {
			$this->setAdminDefaultImageSize($adminConfig['default_image_size']);
		}
		if (isset($adminConfig['image_request_auth'])) {
			$this->setIsImageRetrievalAuthenticated($adminConfig['image_request_auth']);
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
		if (isset($adminConfig['usage_storage_time'])) {
			$this->setUsageStorageTime(intval($adminConfig['usage_storage_time']));
		}
		if (isset($adminConfig['use_max_completion_tokens_param'])) {
			$this->setUseMaxCompletionParam($adminConfig['use_max_completion_tokens_param']);
		}
		if (isset($adminConfig['translation_provider_enabled'])) {
			$this->setTranslationProviderEnabled($adminConfig['translation_provider_enabled']);
		}
		if (isset($adminConfig['llm_provider_enabled'])) {
			$this->setLlmProviderEnabled($adminConfig['llm_provider_enabled']);
		}
		if (isset($adminConfig['t2i_provider_enabled'])) {
			$this->setT2iProviderEnabled($adminConfig['t2i_provider_enabled']);
		}
		if (isset($adminConfig['stt_provider_enabled'])) {
			$this->setSttProviderEnabled($adminConfig['stt_provider_enabled']);
		}
		if (isset($adminConfig['tts_provider_enabled'])) {
			$this->setTtsProviderEnabled($adminConfig['tts_provider_enabled']);
		}
		if (isset($adminConfig['analyze_image_provider_enabled'])) {
			$this->setAnalyzeImageProviderEnabled($adminConfig['analyze_image_provider_enabled']);
		}
		if (isset($adminConfig['default_tts_voice'])) {
			$this->setAdminDefaultTtsVoice($adminConfig['default_tts_voice']);
		}
		if (isset($adminConfig['chat_endpoint_enabled'])) {
			$this->setChatEndpointEnabled($adminConfig['chat_endpoint_enabled']);
		}
		if (isset($adminConfig['basic_user'])) {
			$this->setAdminBasicUser($adminConfig['basic_user']);
		}
		if (isset($adminConfig['basic_password'])) {
			$this->setAdminBasicPassword($adminConfig['basic_password']);
		}
		if (isset($adminConfig['use_basic_auth'])) {
			$this->setUseBasicAuth($adminConfig['use_basic_auth']);
		}
		if (isset($adminConfig['tts_voices'])) {
			$this->setAdminTtsVoices($adminConfig['tts_voices']);
		}

		if (isset($adminConfig['image_url'])) {
			if (str_ends_with($adminConfig['image_url'], '/')) {
				$adminConfig['image_url'] = substr($adminConfig['image_url'], 0, -1) ?: $adminConfig['image_url'];
			}
			$this->setImageUrl($adminConfig['image_url']);
		}
		if (isset($adminConfig['image_service_name'])) {
			$this->setImageServiceName($adminConfig['image_service_name']);
		}
		if (isset($adminConfig['image_api_key'])) {
			$this->setAdminImageApiKey($adminConfig['image_api_key']);
		}
		if (isset($adminConfig['image_basic_user'])) {
			$this->setAdminImageBasicUser($adminConfig['image_basic_user']);
		}
		if (isset($adminConfig['image_basic_password'])) {
			$this->setAdminImageBasicPassword($adminConfig['image_basic_password']);
		}
		if (isset($adminConfig['image_use_basic_auth'])) {
			$this->setAdminImageUseBasicAuth($adminConfig['image_use_basic_auth']);
		}
		if (isset($adminConfig['image_request_timeout'])) {
			$this->setImageRequestTimeout($adminConfig['image_request_timeout']);
		}

		if (isset($adminConfig['stt_url'])) {
			if (str_ends_with($adminConfig['stt_url'], '/')) {
				$adminConfig['stt_url'] = substr($adminConfig['stt_url'], 0, -1) ?: $adminConfig['stt_url'];
			}
			$this->setSttUrl($adminConfig['stt_url']);
		}
		if (isset($adminConfig['stt_service_name'])) {
			$this->setSttServiceName($adminConfig['stt_service_name']);
		}
		if (isset($adminConfig['stt_api_key'])) {
			$this->setAdminSttApiKey($adminConfig['stt_api_key']);
		}
		if (isset($adminConfig['stt_basic_user'])) {
			$this->setAdminSttBasicUser($adminConfig['stt_basic_user']);
		}
		if (isset($adminConfig['stt_basic_password'])) {
			$this->setAdminSttBasicPassword($adminConfig['stt_basic_password']);
		}
		if (isset($adminConfig['stt_use_basic_auth'])) {
			$this->setAdminSttUseBasicAuth($adminConfig['stt_use_basic_auth']);
		}
		if (isset($adminConfig['stt_request_timeout'])) {
			$this->setSttRequestTimeout($adminConfig['stt_request_timeout']);
		}

		if (isset($adminConfig['tts_url'])) {
			if (str_ends_with($adminConfig['tts_url'], '/')) {
				$adminConfig['tts_url'] = substr($adminConfig['tts_url'], 0, -1) ?: $adminConfig['tts_url'];
			}
			$this->setTtsUrl($adminConfig['tts_url']);
		}
		if (isset($adminConfig['tts_service_name'])) {
			$this->setTtsServiceName($adminConfig['tts_service_name']);
		}
		if (isset($adminConfig['tts_api_key'])) {
			$this->setAdminTtsApiKey($adminConfig['tts_api_key']);
		}
		if (isset($adminConfig['tts_basic_user'])) {
			$this->setAdminTtsBasicUser($adminConfig['tts_basic_user']);
		}
		if (isset($adminConfig['tts_basic_password'])) {
			$this->setAdminTtsBasicPassword($adminConfig['tts_basic_password']);
		}
		if (isset($adminConfig['tts_use_basic_auth'])) {
			$this->setAdminTtsUseBasicAuth($adminConfig['tts_use_basic_auth']);
		}
		if (isset($adminConfig['tts_request_timeout'])) {
			$this->setTtsRequestTimeout($adminConfig['tts_request_timeout']);
		}
	}

	/**
	 * Set the user config for the settings page
	 * @param string $userId
	 * @param string[] $userConfig
	 * @throws Exception
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
		if (isset($userConfig['basic_user'])) {
			$this->setUserBasicUser($userId, $userConfig['basic_user']);
		}
		if (isset($userConfig['basic_password'])) {
			$this->setUserBasicPassword($userId, $userConfig['basic_password']);
		}
		if (isset($userConfig['stt_language'])) {
			$this->setUserSttLanguage($userId, $userConfig['stt_language']);
		}
	}

	/**
	 * @param bool $enabled
	 * @return void
	 */
	public function setUseMaxCompletionParam(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'use_max_completion_tokens_param', $enabled ? '1' : '0', lazy: true);
	}

	/**
	 * @param bool $enabled
	 * @return void
	 */
	public function setTranslationProviderEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'translation_provider_enabled', $enabled ? '1' : '0');
	}

	/**
	 * @param bool $enabled
	 * @return void
	 */
	public function setIsImageRetrievalAuthenticated(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'image_request_auth', $enabled ? '1' : '0', lazy: true);
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
	 * @return void
	 */
	public function setT2iProviderEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 't2i_provider_enabled', $enabled ? '1' : '0');
	}

	/**
	 * @param bool $enabled
	 * @return void
	 */
	public function setSttProviderEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'stt_provider_enabled', $enabled ? '1' : '0');
	}

	/**
	 * @param bool $enabled
	 * @return void
	 */
	public function setTtsProviderEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'tts_provider_enabled', $enabled ? '1' : '0');
	}

	/**
	 * @param bool $enabled
	 * @return void
	 */
	public function setAnalyzeImageProviderEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'analyze_image_provider_enabled', $enabled ? '1' : '0');
	}

	/**
	 * @param bool $enabled
	 */
	public function setChatEndpointEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'chat_endpoint_enabled', $enabled ? '1' : '0', lazy: true);
	}

	/**
	 * @return bool
	 */
	public function imageOverrideEnabled(): bool {
		return !empty($this->getImageUrl());
	}

	/**
	 * @return bool
	 */
	public function sttOverrideEnabled(): bool {
		return !empty($this->getSttUrl());
	}

	/**
	 * @return bool
	 */
	public function ttsOverrideEnabled(): bool {
		return !empty($this->getTtsUrl());
	}
}
