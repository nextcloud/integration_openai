<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Migration;

use Closure;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version030900Date20251006152735 extends SimpleMigrationStep {

	public function __construct(
		private OpenAIAPIService $openAIAPIService,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// we refresh the model list to make sure they are stored in oc_appconfig
		// so they are available immediately after the app upgrade to populate the task types enum values
		$this->openAIAPIService->getModels(null, true);
	}
}
