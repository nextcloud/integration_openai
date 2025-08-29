<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

enum EntityType: int {
	case USER = 0;
	case GROUP = 1;
}

/**
 * @extends QBMapper<QuotaUser>
 */
class QuotaUserMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'openai_quota_user', QuotaUser::class);
	}
	/**
	 * @param int $ruleId
	 * @return array
	 * @throws Exception
	 */
	public function getUsers(int $ruleId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}
	/**
	 * @param int $ruleId
	 * @param array $users
	 * @throws Exception
	 */
	public function setUsers(int $ruleId, array $users): void {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$this->db->beginTransaction();
		try {
			$oldUsers = $this->findEntities($qb);
			$oldUsersById = array_reduce($oldUsers, function (array $carry, QuotaUser $oldUser) {
				$carry[$oldUser->getEntityType() . '-' . $oldUser->getEntityId()] = $oldUser;
				return $carry;
			}, []);
			$usersById = [];
			// Add users that are in the new list but not in the old list
			foreach ($users as $user) {
				if (!isset($oldUsersById[$user['entity_type'] . '-' . $user['entity_id']])) {
					$newUser = new QuotaUser();
					$newUser->setRuleId($ruleId);
					$newUser->setEntityType($user['entity_type']);
					$newUser->setEntityId($user['entity_id']);
					$this->insert($newUser);
				}
				$usersById[$user['entity_type'] . '-' . $user['entity_id']] = $user;
			}
			// Delete users that are not in the new list but are in the old list
			foreach ($oldUsers as $oldUser) {
				if (!isset($usersById[$oldUser->getEntityType() . '-' . $oldUser->getEntityId()])) {
					$this->delete($oldUser);
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @param int $ruleId
	 * @throws Exception
	 */
	public function deleteByRuleId(int $ruleId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
