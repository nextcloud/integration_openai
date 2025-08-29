<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version030800Date20250812122830 extends SimpleMigrationStep {

	public function __construct(
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('openai_quota_rule')) {
			$table = $schema->createTable('openai_quota_rule');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('type', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('amount', Types::BIGINT, [
				'notnull' => true
			]);
			$table->addColumn('priority', Types::INTEGER, [
				'notnull' => true
			]);
			$table->addColumn('pool', Types::INTEGER, [
				'notnull' => true
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['type'], 'oai_rule_type');
		}
		if (!$schema->hasTable('openai_quota_user')) {
			$table = $schema->createTable('openai_quota_user');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('rule_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('entity_type', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->addColumn('entity_id', Types::STRING, [
				'notnull' => true,
				'length' => 300,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['rule_id'], 'oai_rule_id');
			$table->addIndex(['entity_id', 'entity_type'], 'oai_user_id_type');
		}
		if ($schema->hasTable('openai_quota_usage')) {
			$table = $schema->getTable('openai_quota_usage');
			if (!$table->hasColumn('pool')) {
				$table->addColumn('pool', Types::BIGINT, [
					'notnull' => true,
					'default' => -1
				]);
				$table->addIndex(['pool'], 'oai_usage_pool');
			}
		}

		return $schema;
	}
}
