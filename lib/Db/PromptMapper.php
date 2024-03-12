<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023, Julien Veyssier <julien-nc@posteo.net>
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
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

use DateTime;
use OCA\OpenAi\AppInfo\Application;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Prompt>
 */
class PromptMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'openai_prompts', Prompt::class);
	}

	/**
	 * @param int $id
	 * @return Prompt
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function getPrompt(int $id): Prompt {
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
	 * @return Prompt
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function getPromptOfUser(int $id, string $userId): Prompt {
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
	 * @param string $userId
	 * @param int $type
	 * @param string $value
	 * @return Prompt
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function getPromptOfUserByValue(string $userId, int $type, string $value): Prompt {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->eq('value', $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param string $userId
	 * @param int|null $type
	 * @return array
	 * @throws Exception
	 */
	public function getPromptsOfUser(string $userId, ?int $type): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			);
		if ($type !== null) {
			$qb->andWhere(
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
			);
		}
		$qb->orderBy('timestamp', 'DESC')
			->setMaxResults(Application::MAX_PROMPT_PER_TYPE_PER_USER);

		return $this->findEntities($qb);
	}

	/**
	 * @param int $type
	 * @param string $userId
	 * @param string $value
	 * @param int|null $timestamp
	 * @return Prompt
	 * @throws Exception
	 */
	public function createPrompt(int $type, string $userId, string $value, ?int $timestamp = null): Prompt {
		try {
			$prompt = $this->getPromptOfUserByValue($userId, $type, $value);
			$ts = (new DateTime())->getTimestamp();
			$prompt->setTimestamp($ts);
			return $this->update($prompt);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
		}

		// if the prompt does not exist, cleanup and create it

		$prompt = new Prompt();
		$prompt->setType($type);
		$prompt->setUserId($userId);
		$prompt->setValue($value);
		if ($timestamp === null) {
			$timestamp = (new DateTime())->getTimestamp();
		}
		$prompt->setTimestamp($timestamp);
		$insertedPrompt = $this->insert($prompt);

		$this->cleanupUserPrompts($userId, $type);

		return $insertedPrompt;
	}

	/**
	 * @param string $userId
	 * @return void
	 * @throws Exception
	 */
	public function deleteUserPrompts(string $userId): void {
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
	 */
	public function deleteUserPromptsByType(string $userId, int $type): void {
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
	 * @param string $userId
	 * @param int $type
	 * @return void
	 * @throws Exception
	 */
	public function cleanupUserPrompts(string $userId, int $type): void {
		$qb = $this->db->getQueryBuilder();

		// get the last N prompts in descending order
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
			)
			->orderBy('timestamp', 'DESC')
			->setMaxResults(Application::MAX_PROMPT_PER_TYPE_PER_USER);

		$req = $qb->executeQuery();

		$lastPromptTs = [];
		while ($row = $req->fetch()) {
			$lastPromptTs[] = (int)$row['timestamp'];
		}
		$req->closeCursor();
		$qb->resetQueryParts();

		// if we have at least 20 prompts stored, delete everything but the last 20 ones
		if (count($lastPromptTs) === Application::MAX_PROMPT_PER_TYPE_PER_USER) {
			$firstPromptTsToKeep = end($lastPromptTs);
			$qb->delete($this->getTableName())
				->where(
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
				)
				->andWhere(
					$qb->expr()->eq('type', $qb->createNamedParameter($type, IQueryBuilder::PARAM_INT))
				)
				->andWhere(
					$qb->expr()->lt('timestamp', $qb->createNamedParameter($firstPromptTsToKeep, IQueryBuilder::PARAM_INT))
				);
			$qb->executeStatement();
		}
	}
}
