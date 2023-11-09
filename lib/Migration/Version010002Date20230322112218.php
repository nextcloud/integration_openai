<?php

declare(strict_types=1);

namespace OCA\OpenAi\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010002Date20230322112218 extends SimpleMigrationStep {
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

		if (!$schema->hasTable('openai_i_gen')) {
			$table = $schema->createTable('openai_i_gen');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('hash', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('prompt', Types::STRING, [
				'notnull' => true,
				'length' => 1000,
			]);
			$table->addColumn('last_used_timestamp', Types::INTEGER, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['hash'], 'openai_i_gen_hash');
		}

		if (!$schema->hasTable('openai_i_url')) {
			$table = $schema->createTable('openai_i_url');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('generation_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('url', Types::STRING, [
				'notnull' => true,
				'length' => 1000,
			]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['generation_id'], 'openai_i_url_gen_id');
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
	}
}
