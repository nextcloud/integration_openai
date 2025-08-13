<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<QuotaRule>
 */
class QuotaRuleMapper extends QBMapper {
	public function __construct(
		IDBConnection $db,
	) {
		parent::__construct($db, 'openai_quota_rule', QuotaRule::class);
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	public function getRules(): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName());

		return $this->findEntities($qb);
	}

	/**
	 * @param int $quotaType
	 * @param string $userId
	 * @param array $groups
	 * @return QuotaRule
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function getRule(int $quotaType, string $userId, array $groups): QuotaRule {
		$qb = $this->db->getQueryBuilder();

		$qb->select('r.*')
			->from($this->getTableName(), 'r')
			->leftJoin('r', 'openai_quota_user', 'u', 'r.id = u.rule_id')
			->where(
				$qb->expr()->eq('r.type', $qb->createNamedParameter($quotaType, IQueryBuilder::PARAM_INT))
			)->andWhere(
				$qb->expr()->orX(
					$qb->expr()->andX(
						$qb->expr()->eq('u.entity_type', $qb->createNamedParameter(EntityType::USER->value, IQueryBuilder::PARAM_INT)),
						$qb->expr()->eq('u.entity_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
					),
					$qb->expr()->andX(
						$qb->expr()->eq('u.entity_type', $qb->createNamedParameter(EntityType::GROUP->value, IQueryBuilder::PARAM_INT)),
						$qb->expr()->in('u.entity_id', $qb->createNamedParameter($groups, IQueryBuilder::PARAM_STR_ARRAY))
					),

				)
			)->orderBy('r.priority', 'ASC')
			->setMaxResults(1);
		/** @var QuotaRule $entity */
		$entity = $this->findEntity($qb);
		return $entity;
	}
	/**
	 * @param int $quotaType
	 * @param int $amount
	 * @param int $priority
	 * @param int $pool
	 * @return int
	 * @throws Exception
	 */
	public function addRule(int $quotaType, int $amount, int $priority, int $pool): int {
		$qb = $this->db->getQueryBuilder();

		$qb->insert($this->getTableName())
			->values(
				[
					'type' => $qb->createNamedParameter($quotaType, IQueryBuilder::PARAM_INT),
					'amount' => $qb->createNamedParameter($amount, IQueryBuilder::PARAM_INT),
					'priority' => $qb->createNamedParameter($priority, IQueryBuilder::PARAM_INT),
					'pool' => $qb->createNamedParameter($pool, IQueryBuilder::PARAM_INT)
				]
			);
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}
	/**
	 * @param int $id
	 * @param int $quotaType
	 * @param int $amount
	 * @param int $priority
	 * @param int $pool
	 * @return void
	 * @throws Exception
	 */
	public function updateRule(int $id, int $quotaType, int $amount, int $priority, int $pool): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update($this->getTableName())
			->set('type', $qb->createNamedParameter($quotaType, IQueryBuilder::PARAM_INT))
			->set('amount', $qb->createNamedParameter($amount, IQueryBuilder::PARAM_INT))
			->set('priority', $qb->createNamedParameter($priority, IQueryBuilder::PARAM_INT))
			->set('pool', $qb->createNamedParameter($pool, IQueryBuilder::PARAM_INT))
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);
		$qb->executeStatement();
	}
	/**
	 * @param int $id
	 * @throws Exception
	 */
	public function deleteRule(int $id): void {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
