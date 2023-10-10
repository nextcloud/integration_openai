<?php
namespace OCA\OpenAi\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCA\OpenAi\AppInfo\Application;

class Admin implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$apiKey = $this->config->getAppValue(Application::APP_ID, 'api_key');
		$defaultAdminCompletionModelId = $this->config->getAppValue(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;
		$serviceUrl = $this->config->getAppValue(Application::APP_ID, 'url');
		$requestTimeout = $this->config->getAppValue(Application::APP_ID, 'request_timeout', Application::OPENAI_DEFAULT_REQUEST_TIMEOUT) ?: Application::OPENAI_DEFAULT_REQUEST_TIMEOUT;

		$whisperPickerEnabled = $this->config->getAppValue(Application::APP_ID, 'whisper_picker_enabled', '1') === '1';
		$imagePickerEnabled = $this->config->getAppValue(Application::APP_ID, 'image_picker_enabled', '1') === '1';
		$textPickerEnabled = $this->config->getAppValue(Application::APP_ID, 'text_completion_picker_enabled', '1') === '1';
		$translationProviderEnabled = $this->config->getAppValue(Application::APP_ID, 'translation_provider_enabled', '1') === '1';
		$sttProviderEnabled = $this->config->getAppValue(Application::APP_ID, 'stt_provider_enabled', '1') === '1';
		
		$maxGeneratedTokens = $this->config->getAppValue(Application::APP_ID, 'max_tokens', Application::DEFAULT_MAX_NUM_OF_TOKENS) ?: Application::DEFAULT_MAX_NUM_OF_TOKENS;
		$quotaPeriod = $this->config->getAppValue(Application::APP_ID, 'quota_period', Application::DEFAULT_QUOTA_PERIOD) ?: Application::DEFAULT_QUOTA_PERIOD;
		$quotas = json_decode($this->config->getAppValue(Application::APP_ID, 'quotas', json_encode(Application::DEFAULT_QUOTAS)) ?: json_encode(Application::DEFAULT_QUOTAS));

		// Make sure all quota types are set in the json encoded app value
		if(count($quotas) !== count(Application::QUOTA_TYPES)) {
			foreach(Application::QUOTA_TYPES as $type => $typeName) {
				if(!isset($quotas[$type])) {
					$quotas[$type] = Application::DEFAULT_QUOTAS[$type];
				}
			}
			$this->config->setAppValue(Application::APP_ID, 'quotas', json_encode($quotas));
		}

		$adminConfig = [
			'request_timeout' => $requestTimeout,
			'url' => $serviceUrl,
			'api_key' => $apiKey,
			'default_completion_model_id' => $defaultAdminCompletionModelId,
			'max_tokens' => $maxGeneratedTokens,
			'quota_period' => $quotaPeriod,
			'quotas' => $quotas,
			'whisper_picker_enabled' => $whisperPickerEnabled,
			'image_picker_enabled' => $imagePickerEnabled,
			'text_completion_picker_enabled' => $textPickerEnabled,
			'translation_provider_enabled' => $translationProviderEnabled,
			'stt_provider_enabled' => $sttProviderEnabled,
		];

		$this->initialStateService->provideInitialState('admin-config', $adminConfig);

		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
