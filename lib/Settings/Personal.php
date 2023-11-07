<?php
namespace OCA\OpenAi\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiSettingsService;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
		private OpenAiSettingsService $openAiSettingsService,
		private ?string $userId
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$userApiKey = $this->openAiSettingsService->getUserApiKey($this->userId);
		$adminServiceUrl = $this->openAiSettingsService->getServiceUrl();
		$isCustomService = $adminServiceUrl !== Application::OPENAI_API_BASE_URL;

		$state = [
			'api_key' => $userApiKey,
			'isCustomService' => $isCustomService,
		];
		$this->initialStateService->provideInitialState('config', $state);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
