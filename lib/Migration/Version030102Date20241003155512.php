<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OpenAi\Migration;

use Closure;
use OCA\OpenAi\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version030102Date20241003155512 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
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
		// app config
		foreach (['api_key', 'basic_password'] as $key) {
			$value = $this->appConfig->getValueString(Application::APP_ID, $key);
			if ($value !== '') {
				$encryptedValue = $this->crypto->encrypt($value);
				$this->appConfig->setValueString(Application::APP_ID, $key, $encryptedValue, lazy: true);
			}
		}

		// user api keys and passwords
		$qbUpdate = $this->connection->getQueryBuilder();
		$qbUpdate->update('preferences')
			->set('configvalue', $qbUpdate->createParameter('updateValue'))
			->where(
				$qbUpdate->expr()->eq('appid', $qbUpdate->createNamedParameter(Application::APP_ID, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qbUpdate->expr()->eq('userid', $qbUpdate->createParameter('updateUserId'))
			)
			->andWhere(
				$qbUpdate->expr()->eq('configkey', $qbUpdate->createParameter('updateConfigKey'))
			);

		$qbSelect = $this->connection->getQueryBuilder();
		$qbSelect->select('userid', 'configvalue', 'configkey')
			->from('preferences')
			->where(
				$qbSelect->expr()->eq('appid', $qbSelect->createNamedParameter(Application::APP_ID, IQueryBuilder::PARAM_STR))
			);

		$or = $qbSelect->expr()->orx();
		$or->add($qbSelect->expr()->eq('configkey', $qbSelect->createNamedParameter('api_key', IQueryBuilder::PARAM_STR)));
		$or->add($qbSelect->expr()->eq('configkey', $qbSelect->createNamedParameter('basic_password', IQueryBuilder::PARAM_STR)));
		$qbSelect->andWhere($or);

		$qbSelect->andWhere(
			$qbSelect->expr()->nonEmptyString('configvalue')
		)
			->andWhere(
				$qbSelect->expr()->isNotNull('configvalue')
			);
		$req = $qbSelect->executeQuery();
		while ($row = $req->fetch()) {
			$userId = $row['userid'];
			$configKey = $row['configkey'];
			$storedClearValue = $row['configvalue'];
			$encryptedValue = $this->crypto->encrypt($storedClearValue);
			$qbUpdate->setParameter('updateConfigKey', $configKey, IQueryBuilder::PARAM_STR);
			$qbUpdate->setParameter('updateValue', $encryptedValue, IQueryBuilder::PARAM_STR);
			$qbUpdate->setParameter('updateUserId', $userId, IQueryBuilder::PARAM_STR);
			$qbUpdate->executeStatement();
		}
		$req->closeCursor();
	}
}
