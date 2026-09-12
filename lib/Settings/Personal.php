<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Settings;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\ServiceConfig;
use OCA\OpenAi\Service\ServicesService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\Settings\ISettings;

class Personal implements ISettings {
	public function __construct(
		private IInitialState $initialStateService,
		private OpenAiSettingsService $openAiSettingsService,
		private ServicesService $servicesService,
		private IL10N $l,
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
		$userConfig = $this->openAiSettingsService->getUserConfig($this->userId);
		$languages = Application::LANGUAGE_CODES_AND_ENDONYMS;
		array_unshift($languages, ['detect_language', $this->l->t('Detect language')]);
		$languages = array_map(static function (array $language) {
			return [
				'value' => $language[0],
				'label' => $language[1],
			];
		}, $languages);
		$this->initialStateService->provideInitialState('languages', $languages);
		$STTLanguage = $userConfig['stt_language'];

		// Sets the correct value and label for the frontend
		$userConfig['stt_language'] = ['value' => '', 'label' => ''];
		foreach ($languages as $language) {
			if ($language['value'] === $STTLanguage) {
				$userConfig['stt_language'] = $language;
				break;
			}
		}
		$this->initialStateService->provideInitialState('config', $userConfig);
		// users can provide their own credentials for any connected service
		$this->initialStateService->provideInitialState('services', array_map(
			static fn (ServiceConfig $service) => $service->jsonSerializeForUser(),
			$this->servicesService->getServices(),
		));
		$this->initialStateService->provideInitialState(
			'user-credentials',
			$this->servicesService->getUserCredentialsForFrontend($this->userId),
		);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'ai';
	}

	public function getPriority(): int {
		return 10;
	}
}
