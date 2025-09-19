<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Settings;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\Settings\ISettings;

class Personal implements ISettings {
	public function __construct(
		private IInitialState $initialStateService,
		private OpenAiSettingsService $openAiSettingsService,
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
		$userConfig['api_key'] = $userConfig['api_key'] === '' ? '' : 'dummyApiKey';
		$userConfig['basic_password'] = $userConfig['basic_password'] === '' ? '' : 'dummyPassword';
		$languages = Application::AUDIO_TO_TEXT_LANGUAGES;
		array_unshift($languages, ['detect_language', $this->l->t('Detect language')]);
		$languages = array_map(static function (array $language) use ($userConfig) {
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
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'ai';
	}

	public function getPriority(): int {
		return 10;
	}
}
