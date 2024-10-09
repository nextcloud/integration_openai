<?php
/**
 * @copyright Copyright (c) 2023, Sami Finnilä (sami.finnila@gmail.com)
 *
 * @author Sami Finnilä <sami.finnila@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\OpenAi\Service;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
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
		'default_image_model_id' => 'string',
		'default_image_size' => 'string',
		'max_tokens' => 'integer',
		'llm_extra_params' => 'string',
		'quota_period' => 'integer',
		'quotas' => 'array',
		'translation_provider_enabled' => 'boolean',
		'llm_provider_enabled' => 'boolean',
		't2i_provider_enabled' => 'boolean',
		'stt_provider_enabled' => 'boolean',
		'chat_endpoint_enabled' => 'boolean',
		'basic_user' => 'string',
		'basic_password' => 'string',
		'use_basic_auth' => 'boolean'
	];

	private const USER_CONFIG_TYPES = [
		'api_key' => 'string',
		'basic_user' => 'string',
		'basic_password' => 'string',
	];


	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
	) {

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
	public function getAdminDefaultImageModelId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'default_image_model_id') ?: Application::DEFAULT_MODEL_ID;
	}

	/**
	 * @return string
	 */
	public function getAdminDefaultImageSize(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'default_image_size') ?: Application::DEFAULT_DEFAULT_IMAGE_SIZE;
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
		return intval($this->appConfig->getValueString(Application::APP_ID, 'request_timeout', strval(Application::OPENAI_DEFAULT_REQUEST_TIMEOUT))) ?: Application::OPENAI_DEFAULT_REQUEST_TIMEOUT;
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
		return $this->appConfig->getValueString(Application::APP_ID, 'basic_user');
	}

	/**
	 * Get admin basic password
	 * @return string
	 * @throws Exception
	 */
	public function getAdminBasicPassword(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'basic_password');
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
			'default_image_model_id' => $this->getAdminDefaultImageModelId(),
			'default_image_size' => $this->getAdminDefaultImageSize(),
			'max_tokens' => $this->getMaxTokens(),
			'llm_extra_params' => $this->getLlmExtraParams(),
			// Updated to get max tokens
			'quota_period' => $this->getQuotaPeriod(),
			// Updated to get quota period
			'quotas' => $this->getQuotas(),
			// Get quotas from the config value and return it
			'translation_provider_enabled' => $this->getTranslationProviderEnabled(),
			'llm_provider_enabled' => $this->getLlmProviderEnabled(),
			't2i_provider_enabled' => $this->getT2iProviderEnabled(),
			'stt_provider_enabled' => $this->getSttProviderEnabled(),
			'chat_endpoint_enabled' => $this->getChatEndpointEnabled(),
			'basic_user' => $this->getAdminBasicUser(),
			'basic_password' => $this->getAdminBasicPassword(),
			'use_basic_auth' => $this->getUseBasicAuth()
		];
	}

	/**
	 * Get the user config for the settings page
	 * @return array{api_key: string, basic_password: string, basic_user: string, is_custom_service: bool, use_basic_auth: bool}
	 */
	public function getUserConfig(string $userId): array {
		$isCustomService = $this->getServiceUrl() !== '' && $this->getServiceUrl() !== Application::OPENAI_API_BASE_URL;
		return [
			'api_key' => $this->getUserApiKey($userId),
			'basic_user' => $this->getUserBasicUser($userId, false),
			'basic_password' => $this->getUserBasicPassword($userId, false),
			'use_basic_auth' => $this->getUseBasicAuth(),
			'is_custom_service' => $isCustomService,

		];
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

		$this->appConfig->setValueString(Application::APP_ID, 'quotas', json_encode($quotas));
	}

	/**
	 * @param string $apiKey
	 * @return void
	 */
	public function setAdminApiKey(string $apiKey): void {
		// No need to validate. As long as it's a string, we're happy campers
		$this->appConfig->setValueString(Application::APP_ID, 'api_key', $apiKey, false, true);
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
	 * @param string $defaultImageModelId
	 * @return void
	 */
	public function setAdminDefaultImageModelId(string $defaultImageModelId): void {
		// No need to validate. As long as it's a string, we're happy campers
		$this->appConfig->setValueString(Application::APP_ID, 'default_image_model_id', $defaultImageModelId);
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
		$this->appConfig->setValueString(Application::APP_ID, 'default_image_size', $defaultImageSize);
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
	 * @param string $basicUser
	 * @return void
	 */
	public function setAdminBasicUser(string $basicUser): void {
		$this->appConfig->setValueString(Application::APP_ID, 'basic_user', $basicUser);
	}

	/**
	 * @param string $basicPassword
	 * @return void
	 */
	public function setAdminBasicPassword(string $basicPassword): void {
		$this->appConfig->setValueString(Application::APP_ID, 'basic_password', $basicPassword, false, true);
	}

	/**
	 * @param string $userId
	 * @param string $basicUser
	 * @return void
	 * @throws PreConditionNotMetException
	 */
	public function setUserBasicUser(string $userId, string $basicUser): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'basic_user', $basicUser);
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
	}

	/**
	 * @param bool $useBasicAuth
	 * @return void
	 */
	public function setUseBasicAuth(bool $useBasicAuth): void {
		$this->appConfig->setValueString(Application::APP_ID, 'use_basic_auth', $useBasicAuth ? '1' : '0');
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
		if (isset($adminConfig['default_image_model_id'])) {
			$this->setAdminDefaultImageModelId($adminConfig['default_image_model_id']);
		}
		if (isset($adminConfig['default_image_size'])) {
			$this->setAdminDefaultImageSize($adminConfig['default_image_size']);
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
		if (isset($userConfig['basic_user'])) {
			$this->setUserBasicUser($userId, $userConfig['basic_user']);
		}
		if (isset($userConfig['basic_password'])) {
			$this->setUserBasicPassword($userId, $userConfig['basic_password']);
		}
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
	 */
	public function setChatEndpointEnabled(bool $enabled): void {
		$this->appConfig->setValueString(Application::APP_ID, 'chat_endpoint_enabled', $enabled ? '1' : '0');
	}
}
