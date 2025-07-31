<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OpenAi\Migration;

use Closure;
use OCA\OpenAi\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version030103Date20241009172829 extends SimpleMigrationStep {

	public function __construct(
		private ICrypto $crypto,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		// switch from manually encrypted to automatically encrypted sensitive value with IAppConfig
		foreach (['api_key', 'basic_password'] as $key) {
			$value = $this->appConfig->getValueString(Application::APP_ID, $key);
			if ($value !== '') {
				$decryptedValue = $this->crypto->decrypt($value);
				$this->appConfig->setValueString(Application::APP_ID, $key, $decryptedValue, true, true);
			}
		}
	}
}
