<?php
namespace OCA\OpenAi\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCA\OpenAi\AppInfo\Application;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
		private ?string $userId
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$userApiKey = $this->config->getUserValue($this->userId, Application::APP_ID, 'api_key');
		$adminServiceUrl = $this->config->getAppValue(Application::APP_ID, 'url', Application::OPENAI_API_BASE_URL) ?: Application::OPENAI_API_BASE_URL;
		$isCustomService = $adminServiceUrl !== Application::OPENAI_API_BASE_URL;

		$userConfig = [
			'api_key' => $userApiKey,
			'isCustomService' => $isCustomService,
		];
		$this->initialStateService->provideInitialState('config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
