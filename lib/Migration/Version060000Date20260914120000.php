<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Migration;

use Closure;
use OCA\OpenAi\Service\ServiceConfig;
use OCA\OpenAi\Service\ServicesService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Makes quota rules apply to a single service instead of to all of them.
 *
 * A rule now carries the ID of the service it governs, so the budget it grants
 * is spent on that service alone. The rules that existed before applied
 * everywhere, so each of them is copied once per configured service: nobody
 * loses a limit over the upgrade, although a user covered by such a rule gets
 * its amount on every service rather than one budget spanning all of them.
 */
class Version060000Date20260914120000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private ServicesService $servicesService,
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
			return null;
		}
		$table = $schema->getTable('openai_quota_rule');
		if ($table->hasColumn('service_id')) {
			return null;
		}
		$table->addColumn('service_id', Types::STRING, [
			'notnull' => false,
			'length' => 64,
			'default' => '',
		]);
		$table->addIndex(['service_id'], 'oai_rule_service');
		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$ruleIds = $this->getUnassignedRuleIds();
		if ($ruleIds === []) {
			return;
		}
		$serviceIds = array_map(
			static fn (ServiceConfig $service) => $service->getId(),
			$this->servicesService->getServices(),
		);
		if ($serviceIds === []) {
			// Without a service there is nothing a rule could govern. The rules
			// are left as they are, so that an admin who connects a service
			// later can assign them to it instead of writing them again.
			$output->warning('Leaving ' . count($ruleIds) . ' quota rule(s) unassigned: no service is configured');
			return;
		}

		$copies = 0;
		foreach ($ruleIds as $ruleId) {
			foreach (array_slice($serviceIds, 1) as $serviceId) {
				$this->copyRule($ruleId, $serviceId);
				$copies++;
			}
			// last, so that an upgrade aborting in between leaves the rule to be
			// picked up again by a re-run rather than half-migrated
			$this->assignRule($ruleId, $serviceIds[0]);
		}

		$output->info('Migrated ' . count($ruleIds) . ' quota rule(s) to ' . count($serviceIds) . ' service(s), creating ' . $copies . ' copies');
	}

	/**
	 * The rules that still apply to every service
	 *
	 * @return list<int>
	 */
	private function getUnassignedRuleIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('openai_quota_rule')
			->where($qb->expr()->orX(
				$qb->expr()->eq('service_id', $qb->createNamedParameter('', IQueryBuilder::PARAM_STR)),
				$qb->expr()->isNull('service_id'),
			));
		$result = $qb->executeQuery();
		$ids = array_map(static fn ($row) => (int)$row['id'], $result->fetchAll());
		$result->closeCursor();
		return $ids;
	}

	private function assignRule(int $ruleId, string $serviceId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('openai_quota_rule')
			->set('service_id', $qb->createNamedParameter($serviceId, IQueryBuilder::PARAM_STR))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Copy a rule, with the users and groups it applies to, over to a service
	 */
	private function copyRule(int $ruleId, string $serviceId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('type', 'amount', 'priority', 'pool')
			->from('openai_quota_rule')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$rule = $result->fetch();
		$result->closeCursor();
		if ($rule === false) {
			return;
		}

		$insert = $this->db->getQueryBuilder();
		$insert->insert('openai_quota_rule')
			->values([
				'type' => $insert->createNamedParameter((int)$rule['type'], IQueryBuilder::PARAM_INT),
				'amount' => $insert->createNamedParameter((int)$rule['amount'], IQueryBuilder::PARAM_INT),
				'priority' => $insert->createNamedParameter((int)$rule['priority'], IQueryBuilder::PARAM_INT),
				'pool' => $insert->createNamedParameter((int)$rule['pool'], IQueryBuilder::PARAM_INT),
				'service_id' => $insert->createNamedParameter($serviceId, IQueryBuilder::PARAM_STR),
			]);
		$insert->executeStatement();
		$copyId = $insert->getLastInsertId();

		$qb = $this->db->getQueryBuilder();
		$qb->select('entity_type', 'entity_id')
			->from('openai_quota_user')
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$entities = $result->fetchAll();
		$result->closeCursor();

		foreach ($entities as $entity) {
			$insert = $this->db->getQueryBuilder();
			$insert->insert('openai_quota_user')
				->values([
					'rule_id' => $insert->createNamedParameter($copyId, IQueryBuilder::PARAM_INT),
					'entity_type' => $insert->createNamedParameter((int)$entity['entity_type'], IQueryBuilder::PARAM_INT),
					'entity_id' => $insert->createNamedParameter((string)$entity['entity_id'], IQueryBuilder::PARAM_STR),
				]);
			$insert->executeStatement();
		}
	}
}
