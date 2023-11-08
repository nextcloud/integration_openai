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
use OCP\IConfig;
use OCA\OpenAi\AppInfo\Application;

class OpenAiSettingsService
{

    private const ADMIN_CONFIG_TYPES = [
        'request_timeout' => 'integer',
        'url' => 'string',
        'api_key' => 'string',
        'default_completion_model_id' => 'string',
        'max_tokens' => 'integer',
        'quota_period' => 'integer',
        'quotas' => 'array',
        'whisper_picker_enabled' => 'boolean',
        'image_picker_enabled' => 'boolean',
        'text_completion_picker_enabled' => 'boolean',
        'translation_provider_enabled' => 'boolean',
        'stt_provider_enabled' => 'boolean'
    ];

    private const USER_CONFIG_TYPES = [
        'api_key' => 'string',
        'default_completion_model_id' => 'string'
    ];


    public function __construct(private IConfig $config)
    {

    }

    ////////////////////////////////////////////
    //////////// Getters for settings //////////

    /**
     * @return string
     */
    public function getAdminApiKey(): string
    {
        return $this->config->getAppValue(Application::APP_ID, 'api_key');
    }

    /**
     * @param string $userId
     * @return string
     */
    public function getUserApiKey(string $userId): string
    {
        // SIC! Do not fall back on the admin api key if the user has not set their own api key
        $userApiKey = $this->config->getUserValue($userId, Application::APP_ID, 'api_key', '') ?: '';
        return $userApiKey;
    }

    /**
     * @return string
     */
    public function getAdminDefaultCompletionModelId(): string
    {
        return $this->config->getAppValue(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;
    }

    /**
     * @return string
     */
    public function getServiceUrl(): string
    {
        return $this->config->getAppValue(Application::APP_ID, 'url', '');
    }

    /**
     * @return int
     */
    public function getRequestTimeout(): int
    {
        return intval($this->config->getAppValue(Application::APP_ID, 'request_timeout', strval(Application::OPENAI_DEFAULT_REQUEST_TIMEOUT))) ?: Application::OPENAI_DEFAULT_REQUEST_TIMEOUT;
    }

    /**
     * @return int
     */
    public function getMaxTokens(): int
    {
        return intval($this->config->getAppValue(Application::APP_ID, 'max_tokens', strval(Application::DEFAULT_MAX_NUM_OF_TOKENS))) ?: Application::DEFAULT_MAX_NUM_OF_TOKENS;
    }

    /**
     * @return int
     */
    public function getQuotaPeriod(): int
    {
        return intval($this->config->getAppValue(Application::APP_ID, 'quota_period', strval(Application::DEFAULT_QUOTA_PERIOD))) ?: Application::DEFAULT_QUOTA_PERIOD;
    }

    /**
     * @return int[]
     */
    public function getQuotas(): array
    {
        $quotas = json_decode($this->config->getAppValue(Application::APP_ID, 'quotas', json_encode(Application::DEFAULT_QUOTAS)) ?: json_encode(Application::DEFAULT_QUOTAS), true);
        // Make sure all quota types are set in the json encoded app value (in case new quota types are added in the future)
        if (count($quotas) !== count(Application::DEFAULT_QUOTAS)) {
            foreach (Application::DEFAULT_QUOTAS as $quotaType => $_) {
                if (!isset($quotas[$quotaType])) {
                    $quotas[$quotaType] = Application::DEFAULT_QUOTAS[$quotaType];
                }
            }
            $this->config->setAppValue(Application::APP_ID, 'quotas', json_encode($quotas));
        }

        return $quotas;
    }

    /**
     * Get the admin config for the settings page 
     * @return mixed[] 
     */
    public function getAdminConfig(): array
    {
        return [
            'request_timeout' => $this->getRequestTimeout(),
            'url' => $this->getServiceUrl(),
            'api_key' => $this->getAdminApiKey(),
            'default_completion_model_id' => $this->getAdminDefaultCompletionModelId(),
            'max_tokens' => $this->getMaxTokens(),
            // Updated to get max tokens
            'quota_period' => $this->getQuotaPeriod(),
            // Updated to get quota period
            'quotas' => $this->getQuotas(),
            // Get quotas from the config value and return it
            'whisper_picker_enabled' => $this->getWhisperPickerEnabled(),
            'image_picker_enabled' => $this->getImagePickerEnabled(),
            'text_completion_picker_enabled' => $this->getTextCompletionPickerEnabled(),
            'translation_provider_enabled' => $this->getTranslationProviderEnabled(),
            'stt_provider_enabled' => $this->getSttProviderEnabled()
        ];
    }

    /**
     * Get the user config for the settings page
     * @return string[]
     */
    public function getUserConfig(string $userId): array
    {
        return [
            'api_key' => $this->getUserApiKey($userId),
            'default_completion_model_id' => $this->getUserDefaultCompletionModelId($userId)
        ];
    }

    /**
     * @return bool
     */
    public function getWhisperPickerEnabled(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'whisper_picker_enabled', '1') === '1';
    }
    /**
     * @return bool
     */
    public function getImagePickerEnabled(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'image_picker_enabled', '1') === '1';
    }
    /**
     * @return bool
     */
    public function getTextCompletionPickerEnabled(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'text_completion_picker_enabled', '1') === '1';
    }
    /**
     * @return bool
     */
    public function getTranslationProviderEnabled(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'translation_provider_enabled', '1') === '1';
    }
    /**
     * @return bool
     */
    public function getSttProviderEnabled(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'stt_provider_enabled', '1') === '1';
    }

    /**
     * @param string $userId
     * @return string
     */
    public function getUserDefaultCompletionModelId(string $userId): string
    {
        // Fall back on admin model setting if necessary:
        $adminModel = $this->getAdminDefaultCompletionModelId();
        return $this->config->getUserValue($userId, Application::APP_ID, 'default_completion_model_id', $adminModel) ?: $adminModel;
    }

    ////////////////////////////////////////////
    //////////// Setters for settings //////////

    /**
     * @param int[] $quotas
     * @return void
     * @throws Exception
     */
    public function setQuotas(array $quotas): void
    {
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

        $this->config->setAppValue(Application::APP_ID, 'quotas', json_encode($quotas));
    }

    /**
     * @param string $apiKey
     * @return void
     */
    public function setAdminApiKey(string $apiKey): void
    {
        // No need to validate. As long as it's a string, we're happy campers
        $this->config->setAppValue(Application::APP_ID, 'api_key', $apiKey);
    }

    /**
     * @param string $userId
     * @param string $apiKey
     */
    public function setUserApiKey(string $userId, string $apiKey): void
    {
        // No need to validate. As long as it's a string, we're happy campers
        $this->config->setUserValue($userId, Application::APP_ID, 'api_key', $apiKey);
    }

    /**
     * @param string $defaultCompletionModelId
     * @return void
     */
    public function setAdminDefaultCompletionModelId(string $defaultCompletionModelId): void
    {
        // No need to validate. As long as it's a string, we're happy campers
        $this->config->setAppValue(Application::APP_ID, 'default_completion_model_id', $defaultCompletionModelId);
    }

    /**
     * @param string $userId
     * @param string $defaultCompletionModelId
     */
    public function setUserDefaultCompletionModelId(string $userId, string $defaultCompletionModelId): void
    {
        // No need to validate. As long as it's a string, we're happy campers
        $this->config->setUserValue($userId, Application::APP_ID, 'default_completion_model_id', $defaultCompletionModelId);
    }

    /**
     * @param string $serviceUrl
     * @return void
     * @throws Exception
     */
    public function setServiceUrl(string $serviceUrl): void
    {
        // Validate input:
        if (!filter_var($serviceUrl, FILTER_VALIDATE_URL) && $serviceUrl !== '') {
            throw new Exception('Invalid service URL');
        }
        $this->config->setAppValue(Application::APP_ID, 'url', $serviceUrl);
    }
    /**
     * @param int $requestTimeout
     * @return void
     */
    public function setRequestTimeout(int $requestTimeout): void
    {
        // Validate input:
        $requestTimeout = max(1, $requestTimeout);
        $this->config->setAppValue(Application::APP_ID, 'request_timeout', $requestTimeout);
    }

    /**
     * Setter for maxTokens; minimum is 100
     * @param int $maxTokens
     * @return void
     */
    public function setMaxTokens(int $maxTokens): void
    {
        // Validate input:
        $maxTokens = max(100, $maxTokens);
        $this->config->setAppValue(Application::APP_ID, 'max_tokens', $maxTokens);
    }

    /**
     * Setter for quotaPeriod; minimum is 1 day
     * @param string $quotaPeriod
     * @return void
     */
    public function setQuotaPeriod(int $quotaPeriod): void
    {
        // Validate input:
        $quotaPeriod = max(1, $quotaPeriod);
        $this->config->setAppValue(Application::APP_ID, 'quota_period', $quotaPeriod);
    }

    /**
     * Set the admin config for the settings page
     * @param mixed[] $config
     * @return void
     * @throws Exception
     */
    public function setAdminConfig(array $adminConfig): void
    {
        // That the variable types are correct
        foreach (array_keys($adminConfig) as $key) {
            if (gettype($adminConfig[$key]) !== self::ADMIN_CONFIG_TYPES[$key]) {
                throw new Exception('Invalid type for key: ' . $key . '. Expected ' . self::ADMIN_CONFIG_TYPES[$key] . ', got ' . gettype($adminConfig[$key]));
            }
        }

        // Validation of the input values is done in the individual setters
        if (isset($adminConfig['request_timeout']))
            $this->setRequestTimeout($adminConfig['request_timeout']);
        if (isset($adminConfig['url']))
            $this->setServiceUrl($adminConfig['url']);
        if (isset($adminConfig['api_key']))
            $this->setAdminApiKey($adminConfig['api_key']);
        if (isset($adminConfig['default_completion_model_id']))
            $this->setAdminDefaultCompletionModelId($adminConfig['default_completion_model_id']);
        if (isset($adminConfig['max_tokens']))
            $this->setMaxTokens($adminConfig['max_tokens']);
        if (isset($adminConfig['quota_period']))
            $this->setQuotaPeriod($adminConfig['quota_period']);
        if (isset($adminConfig['quotas']))
            $this->setQuotas($adminConfig['quotas']);
        if (isset($adminConfig['whisper_picker_enabled']))
            $this->setWhisperPickerEnabled($adminConfig['whisper_picker_enabled']);
        if (isset($adminConfig['image_picker_enabled']))
            $this->setImagePickerEnabled($adminConfig['image_picker_enabled']);
        if (isset($adminConfig['text_completion_picker_enabled']))
            $this->setTextCompletionPickerEnabled($adminConfig['text_completion_picker_enabled']);
        if (isset($adminConfig['translation_provider_enabled']))
            $this->setTranslationProviderEnabled($adminConfig['translation_provider_enabled']);
        if (isset($adminConfig['stt_provider_enabled']))
            $this->setSttProviderEnabled($adminConfig['stt_provider_enabled']);

    }

    /**
     * Set the user config for the settings page
     * @param string $userId
     * @param string[] $userConfig
     */
    public function setUserConfig(string $userId, array $userConfig): void
    {
        // That the variable types are correct
        foreach (array_keys($userConfig) as $key) {
            if (gettype($userConfig[$key]) !== self::USER_CONFIG_TYPES[$key]) {
                throw new Exception('Invalid type for key: ' . $key . '. Expected ' . self::ADMIN_CONFIG_TYPES[$key] . ', got ' . gettype($userConfig[$key]));
            }
        }

        // Validation of the input values is done in the individual setters
        if (isset($userConfig['api_key']))
            $this->setUserApiKey($userId, $userConfig['api_key']);
        if (isset($userConfig['default_completion_model_id']))
            $this->setUserDefaultCompletionModelId($userId, $userConfig['default_completion_model_id']);
    }

    // Setters and getters for missing settings
    /**
     * @param bool $enabled
     * @return void
     */
    public function setWhisperPickerEnabled(bool $enabled): void
    {
        $this->config->setAppValue(Application::APP_ID, 'whisper_picker_enabled', $enabled ? '1' : '0');
    }
    /**
     * @param bool $enabled
     * @return void
     */
    public function setImagePickerEnabled(bool $enabled): void
    {
        $this->config->setAppValue(Application::APP_ID, 'image_picker_enabled', $enabled ? '1' : '0');
    }
    /**
     * @param bool $enabled
     * @return void
     */
    public function setTextCompletionPickerEnabled(bool $enabled): void
    {
        $this->config->setAppValue(Application::APP_ID, 'text_completion_picker_enabled', $enabled ? '1' : '0');
    }
    /**
     * @param bool $enabled
     * @return void
     */
    public function setTranslationProviderEnabled(bool $enabled): void
    {
        $this->config->setAppValue(Application::APP_ID, 'translation_provider_enabled', $enabled ? '1' : '0');
    }
    /**
     * @param bool $enabled
     * @return void
     */
    public function setSttProviderEnabled(bool $enabled): void
    {
        $this->config->setAppValue(Application::APP_ID, 'stt_provider_enabled', $enabled ? '1' : '0');
    }

    /**
     * @param string $userId
     * @param string $imageSize
     * @return void
     */
    public function setLastImageSize(string $userId, string $imageSize): void
    {
        $this->config->setUserValue($userId, Application::APP_ID, 'last_image_size', $imageSize);
    }
}