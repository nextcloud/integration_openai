<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Capabilities\IPublicCapability;

class Capabilities implements IPublicCapability {
	public function __construct(
		private OpenAiAPIService $openAiAPIService,
	) {
	}

	public function getCapabilities(): array {
		return [
			Application::APP_ID => [
				'uses_openai' => $this->openAiAPIService->isUsingOpenAi(),
			],
		];
	}
}
