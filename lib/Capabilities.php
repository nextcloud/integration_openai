<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\Capabilities\IPublicCapability;

class Capabilities implements IPublicCapability {
	public function __construct(
		private OpenAiSettingsService $openAiSettingsService,
	) {
	}

	public function getCapabilities(): array {
		return [
			Application::APP_ID => [
				'uses_openai' => $this->openAiSettingsService->isUsingOpenAi(),
			],
		];
	}
}
