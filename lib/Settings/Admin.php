<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Settings;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\QuotaRuleService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialStateService,
		private OpenAiSettingsService $openAiSettingsService,
		private QuotaRuleService $quotaRuleService,
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
		$adminConfig['image_api_key'] = $adminConfig['image_api_key'] === '' ? '' : 'dummyApiKey';
		$adminConfig['image_basic_password'] = $adminConfig['image_basic_password'] === '' ? '' : 'dummyPassword';
		$adminConfig['stt_api_key'] = $adminConfig['stt_api_key'] === '' ? '' : 'dummyApiKey';
		$adminConfig['stt_basic_password'] = $adminConfig['stt_basic_password'] === '' ? '' : 'dummyPassword';
		$adminConfig['tts_api_key'] = $adminConfig['tts_api_key'] === '' ? '' : 'dummyApiKey';
		$adminConfig['tts_basic_password'] = $adminConfig['tts_basic_password'] === '' ? '' : 'dummyPassword';
		$isAssistantEnabled = $this->appManager->isEnabledForUser('assistant');
		$adminConfig['assistant_enabled'] = $isAssistantEnabled;
		$adminConfig['quota_start_date'] = $this->openAiSettingsService->getQuotaStart();
		$adminConfig['quota_end_date'] = $this->openAiSettingsService->getQuotaEnd();
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
		$rules = $this->quotaRuleService->getRules();
		$this->initialStateService->provideInitialState('rules', $rules);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'ai';
	}

	public function getPriority(): int {
		return 10;
	}
}
