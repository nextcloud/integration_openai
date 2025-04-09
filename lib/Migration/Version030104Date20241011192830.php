<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Migration;

use Closure;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version030104Date20241011192830 extends SimpleMigrationStep {

	public function __construct(
		private OpenAiSettingsService $openAiSettingsService,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$value = $this->openAiSettingsService->getServiceUrl();
		if ($value !== '' && !str_ends_with(rtrim($value, '/ '), '/v1')) {
			$newValue = rtrim($value, '/') . '/v1';
			$this->openAiSettingsService->setServiceUrl($newValue);
		}
	}
}
