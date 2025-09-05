<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Db;

use DateInterval;
use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use RuntimeException;

/**
 * @extends QBMapper<QuotaUsage>
 */
class QuotaUsageMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'openai_quota_usage', QuotaUsage::class);
	}

	/**
	 * @param int $id
	 * @return QuotaUsage
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function getQuotaUsage(int $id): QuotaUsage {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param int $id
	 * @param string $userId
	 * @return QuotaUsage
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function getQuotaUsageOfUser(int $id, string $userId): QuotaUsage {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param int $type Type of the quota
	 * @param int $periodStart Start time of quota
	 * @return int
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 * @throws \RuntimeException
	 */
	public function getQuotaUnitsInTimePeriod(int $type, int $periodStart): int {
		$qb = $this->db->getQueryBuilder();

		// Get the sum of the units used in the time period
		$qb->select($qb->func()->sum('units'))
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->gt('timestamp', $qb->createNamedParameter($periodStart, IQueryBuilder::PARAM_INT))
			);

		// Execute the query and return the result
		$result = (int)$qb->executeQuery()->fetchOne();
		$qb->resetQueryParts();

		return $result;
	}

	/**
	 * @param string $userId
	 * @param int $type Type of the quota
	 * @param int $periodStart Start time of quota
	 * @param int|null $pool
	 * @return int
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 * @throws RuntimeException
	 */
	public function getQuotaUnitsOfUserInTimePeriod(string $userId, int $type, int $periodStart, ?int $pool = null): int {
		$qb = $this->db->getQueryBuilder();

		// Get the sum of the units used in the time period
		$qb->select($qb->func()->sum('units'))
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->gt('timestamp', $qb->createNamedParameter($periodStart, IQueryBuilder::PARAM_INT))
			);
		if ($pool === null) {
			$qb->andWhere(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			);
		} else {
			$qb->andWhere(
				$qb->expr()->eq('pool', $qb->createNamedParameter($pool, IQueryBuilder::PARAM_INT))
			);
		}

		// Execute the query and return the result
		$result = (int)$qb->executeQuery()->fetchOne();
		$qb->resetQueryParts();

		return $result;
	}

	/**
	 * @param string $userId
	 * @param int $type
	 * @return array
	 * @throws Exception
	 */
	public function getQuotaUsagesOfUser(string $userId, int $type): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			)->andWhere(
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
			);

		$qb->orderBy('timestamp', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * @param string $userId
	 * @param int $type
	 * @return int
	 * @throws Exception
	 * @throws \RuntimeException
	 */
	public function getQuotaUnitsOfUser(string $userId, int $type): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->func()->sum('units'))
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			)->andWhere(
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
			);

		// Execute the query and return the result
		$result = (int)$qb->executeQuery()->fetchOne();
		$qb->resetQueryParts();

		return $result;
	}

	/**
	 * @param string $userId
	 * @param int $type
	 * @param int $units
	 * @param int $pool
	 * @return QuotaUsage
	 * @throws Exception
	 */
	public function createQuotaUsage(string $userId, int $type, int $units, int $pool = -1): QuotaUsage {

		$quotaUsage = new QuotaUsage();
		$quotaUsage->setUserId($userId);
		$quotaUsage->setType($type);
		$quotaUsage->setUnits($units);
		$quotaUsage->setPool($pool);
		$quotaUsage->setTimestamp((new DateTime())->getTimestamp());
		$insertedQuotaUsage = $this->insert($quotaUsage);

		return $insertedQuotaUsage;
	}

	/**
	 * @param string $userId
	 * @return void
	 * @throws Exception
	 * @throws \RuntimeException
	 */
	public function deleteUserQuotaUsages(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			);
		$qb->executeStatement();
	}

	/**
	 * Delete user prompts by type
	 * @param string $userId
	 * @param int $type
	 * @return void
	 * @throws Exception
	 * @throws \RuntimeException
	 */
	public function deleteUserQuotaUsagesByType(string $userId, int $type): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
			);
		$qb->executeStatement();
	}

	/**
	 * Delete quota usages by type
	 * @param int $type
	 * @return void
	 * @throws Exception
	 * @throws \RuntimeException
	 */
	public function deleteQuotaUsagesByType(int $type): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
			);
		$qb->executeStatement();
	}

	/**
	 * Delete quota usages older than the time period
	 * @param int $timePeriod Time period in days
	 * @return void
	 * @throws Exception
	 * @throws \RuntimeException
	 */
	public function cleanupQuotaUsages(int $timePeriod): void {
		$qb = $this->db->getQueryBuilder();

		$periodStart = (new DateTime())->sub(new DateInterval('P' . $timePeriod . 'D'))->getTimestamp();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->lt('timestamp', $qb->createNamedParameter($periodStart, IQueryBuilder::PARAM_INT))
			);
		$qb->executeStatement();
	}

	/**
	 * Gets quota usage of all users
	 * @param int $startTime
	 * @param int $endTime
	 * @return array
	 * @throws Exception
	 * @throws RuntimeException
	 */
	public function getUsersQuotaUsage(int $startTime, int $endTime, $type): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('user_id')
			->selectAlias($qb->func()->sum('units'), 'usage')
			->from($this->getTableName())
			->where($qb->expr()->gte('timestamp', $qb->createNamedParameter($startTime, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('timestamp', $qb->createNamedParameter($endTime, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT)))
			->groupBy('user_id')
			->orderBy('usage', 'DESC');

		return $qb->executeQuery()->fetchAll();
	}
	/**
	 * Gets quota usage of all pools
	 * @param int $startTime
	 * @param int $endTime
	 * @param int $type
	 * @return array
	 * @throws Exception
	 * @throws RuntimeException
	 */
	public function getPoolsQuotaUsage(int $startTime, int $endTime, int $type): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('pool')
			->selectAlias($qb->func()->sum('units'), 'usage')
			->from($this->getTableName())
			->where($qb->expr()->neq('pool', $qb->createNamedParameter(-1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gte('timestamp', $qb->createNamedParameter($startTime, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('timestamp', $qb->createNamedParameter($endTime, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT)))
			->groupBy('type', 'pool')
			->orderBy('usage', 'DESC');

		return $qb->executeQuery()->fetchAll();
	}
}
