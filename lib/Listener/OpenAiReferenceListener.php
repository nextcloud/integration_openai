<?php
/**
 * @copyright Copyright (c) 2022 Julien Veyssier <julien-nc@posteo.net>
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\OpenAi\Listener;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\AppFramework\Services\IInitialState;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Util;

/**
 * @implements IEventListener<RenderReferenceEvent>
 */
class OpenAiReferenceListener implements IEventListener {
	public function __construct(
		private IConfig $config,
		private IInitialState $initialState,
		private OpenAiSettingsService $settingsService,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof RenderReferenceEvent) {
			return;
		}

		$whisperPickerEnabled = $this->settingsService->getWhisperPickerEnabled();
		$imagePickerEnabled = $this->settingsService->getImagePickerEnabled();
		$textPickerEnabled = $this->settingsService->getTextCompletionPickerEnabled();
		$translationProviderEnabled = $this->settingsService->getTranslationProviderEnabled();
		$sttProviderEnabled = $this->settingsService->getSttProviderEnabled();

		$features = [
			'whisper_picker_enabled' => $whisperPickerEnabled,
			'image_picker_enabled' => $imagePickerEnabled,
			'text_completion_picker_enabled' => $textPickerEnabled,
			'translation_provider_enabled' => $translationProviderEnabled,
			'stt_provider_enabled' => $sttProviderEnabled,
		];
		$this->initialState->provideInitialState('features', $features);
		Util::addScript(Application::APP_ID, Application::APP_ID . '-reference');
	}
}
