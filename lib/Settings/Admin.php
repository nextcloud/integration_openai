<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Settings;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialStateService,
		private OpenAiSettingsService $openAiSettingsService,
		private IAppManager $appManager,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$adminConfig = $this->openAiSettingsService->getAdminConfig();
		$adminConfig['api_key'] = $adminConfig['api_key'] === '' ? '' : 'dummyApiKey';
		$adminConfig['basic_password'] = $adminConfig['basic_password'] === '' ? '' : 'dummyPassword';
		$isAssistantEnabled = $this->appManager->isEnabledForUser('assistant');
		$adminConfig['assistant_enabled'] = $isAssistantEnabled;
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'ai';
	}

	public function getPriority(): int {
		return 10;
	}
}
