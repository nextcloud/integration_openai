<?php

declare(strict_types=1);

namespace OCA\OpenAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version020000Date20240325142435 extends SimpleMigrationStep {
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

		if ($schema->hasTable('openai_prompts')) {
			$table = $schema->getTable('openai_prompts');
			$table->dropIndex('openai_prompt_userid');
			$schema->dropTable('openai_prompts');
			$schemaChanged = true;
		}

		if ($schema->hasTable('openai_i_gen')) {
			$table = $schema->getTable('openai_i_gen');
			$table->dropIndex('openai_i_gen_hash');
			$schema->dropTable('openai_i_gen');
			$schemaChanged = true;
		}

		if ($schema->hasTable('openai_i_url')) {
			$table = $schema->getTable('openai_i_url');
			$table->dropIndex('openai_i_url_gen_id');
			$schema->dropTable('openai_i_url');
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
