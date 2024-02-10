<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023, Sami Finnilä (sami.finnila@gmail.com)
 *
 * @author Sami Finnilä <sami.finnila@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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
	 * @param int $timePeriod Time period in days
	 * @return int
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 * @throws \RuntimeException
	 */
	public function getQuotaUnitsInTimePeriod(int $type, int $timePeriod): int {
		$qb = $this->db->getQueryBuilder();

		// Get a timestamp of the beginning of the time period
		$periodStart = (new DateTime())->sub(new DateInterval('P' . $timePeriod . 'D'))->getTimestamp();

		// Get the sum of the units used in the time period
		$qb->select($qb->createFunction('SUM(units)'))
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
	 * @param int $timePeriod Time period in days
	 * @return int
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 * @throws \RuntimeException
	 */
	public function getQuotaUnitsOfUserInTimePeriod(string $userId, int $type, int $timePeriod): int {
		$qb = $this->db->getQueryBuilder();

		// Get a timestamp of the beginning of the time period
		$periodStart = (new DateTime())->sub(new DateInterval('P' . $timePeriod . 'D'))->getTimestamp();

		// Get the sum of the units used in the time period
		$qb->select($qb->createFunction('SUM(units)'))
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
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

		$qb->select($qb->createFunction('SUM(units)'))
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
	 * @return QuotaUsage
	 * @throws Exception
	 */
	public function createQuotaUsage(string $userId, int $type, int $units): QuotaUsage {

		$quotaUsage = new QuotaUsage();
		$quotaUsage->setUserId($userId);
		$quotaUsage->setType($type);
		$quotaUsage->setUnits($units);
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
}
