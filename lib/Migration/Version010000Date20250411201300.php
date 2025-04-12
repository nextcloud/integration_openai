<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010000Date20250411201300 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$schemaChanged = false;

		if ($schema->hasTable('watsonx_prompts')) {
			$table = $schema->getTable('watsonx_prompts');
			if ($table->hasIndex('watsonx_prompt_userid')) {
				$table->dropIndex('watsonx_prompt_userid');
			}
			$schema->dropTable('watsonx_prompts');
			$schemaChanged = true;
		}

		if ($schema->hasTable('watsonx_i_gen')) {
			$table = $schema->getTable('watsonx_i_gen');
			if ($table->hasIndex('watsonx_i_gen_hash')) {
				$table->dropIndex('watsonx_i_gen_hash');
			}
			$schema->dropTable('watsonx_i_gen');
			$schemaChanged = true;
		}

		if ($schema->hasTable('watsonx_i_url')) {
			$table = $schema->getTable('watsonx_i_url');
			if ($table->hasIndex('watsonx_i_url_gen_id')) {
				$table->dropIndex('watsonx_i_url_gen_id');
			}
			$schema->dropTable('watsonx_i_url');
			$schemaChanged = true;
		}

		return $schemaChanged ? $schema : null;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
	}
}
