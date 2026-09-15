<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Settings;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\QuotaRuleService;
use OCA\OpenAi\Service\ServicesService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialStateService,
		private OpenAiSettingsService $openAiSettingsService,
		private ServicesService $servicesService,
		private QuotaRuleService $quotaRuleService,
		private IAppManager $appManager,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$adminConfig = $this->openAiSettingsService->getAdminConfig();
		$adminConfig['assistant_enabled'] = $this->appManager->isEnabledForUser('assistant');
		$adminConfig['quota_start_date'] = $this->openAiSettingsService->getQuotaStart();
		$adminConfig['quota_end_date'] = $this->openAiSettingsService->getQuotaEnd();
		$this->initialStateService->provideInitialState('admin-config', $adminConfig);
		$this->initialStateService->provideInitialState('services', $this->servicesService->getServicesForFrontend());
		$this->initialStateService->provideInitialState('rules', $this->quotaRuleService->getRules());
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'ai';
	}

	public function getPriority(): int {
		return 10;
	}
}
