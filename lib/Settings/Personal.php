<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\Settings;

use OCA\Watsonx\AppInfo\Application;
use OCA\Watsonx\Service\WatsonxSettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class Personal implements ISettings {
	public function __construct(
		private IInitialState $initialStateService,
		private WatsonxSettingsService $watsonxSettingsService,
		private ?string $userId,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		if ($this->userId === null) {
			return new TemplateResponse(Application::APP_ID, 'personalSettings');
		}
		$userConfig = $this->watsonxSettingsService->getUserConfig($this->userId);
		$userConfig['api_key'] = $userConfig['api_key'] === '' ? '' : 'dummyApiKey';
		$this->initialStateService->provideInitialState('config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'ai';
	}

	public function getPriority(): int {
		return 10;
	}
}
