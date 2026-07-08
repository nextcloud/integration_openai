<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Migration;

use Closure;
use OCA\OpenAi\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version040600Date20260708151000 extends SimpleMigrationStep {

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$unset = '__unset__';
		$newValue = $this->appConfig->getValueString(Application::APP_ID, 'multimodal_image_enabled', $unset);
		if ($newValue !== $unset) {
			return;
		}

		$oldValue = $this->appConfig->getValueString(Application::APP_ID, 'analyze_image_provider_enabled', $unset);
		if (!in_array($oldValue, ['0', '1'], true)) {
			return;
		}

		$this->appConfig->setValueString(Application::APP_ID, 'multimodal_image_enabled', $oldValue);
	}
}
