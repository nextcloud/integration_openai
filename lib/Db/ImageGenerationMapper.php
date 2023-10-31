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
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

use OCP\AppFramework\Db\DoesNotExistException;

class ImageGenerationMapper extends QBMapper {

	public function __construct(IDBConnection  $db,
								private ImageUrlMapper $imageUrlMapper) {
		parent::__construct($db, 'openai_i_gen', ImageGeneration::class);
	}

	/**
	 * @param int $id
	 * @return ImageGeneration
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function getImageGeneration(int $id): ImageGeneration {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param string $hash
	 * @return ImageGeneration
	 * @throws DoesNotExistException
	 * @throws Exception
	 * @throws MultipleObjectsReturnedException
	 */
	public function getImageGenerationFromHash(string $hash): ImageGeneration {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('hash', $qb->createNamedParameter($hash, IQueryBuilder::PARAM_STR))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param string $hash
	 * @param string $prompt
	 * @param int $lastUsedTimestamp
	 * @return ImageGeneration
	 * @throws Exception
	 */
	public function createImageGeneration(string $hash, string $prompt, int $lastUsedTimestamp, array $urls): ImageGeneration {
		$imageGeneration = new ImageGeneration();
		$imageGeneration->setHash($hash);
		$imageGeneration->setPrompt($prompt);
		$imageGeneration->setLastUsedTimestamp($lastUsedTimestamp);
		$insertedImageGeneration = $this->insert($imageGeneration);

		$insertedId = $insertedImageGeneration->getId();
		foreach ($urls as $url) {
			$this->imageUrlMapper->createImageUrl($insertedId, $url);
		}

		return $insertedImageGeneration;
	}

	/**
	 * @param int $id
	 * @return mixed|Entity
	 * @throws Exception
	 */
	public function touchImageGeneration(int $id) {
		try {
			$imageGeneration = $this->getImageGeneration($id);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			return null;
		}
		$ts = (new DateTime())->getTimestamp();
		$imageGeneration->setLastUsedTimestamp($ts);
		return $this->update($imageGeneration);
	}

	/**
	 * @param int $id
	 * @return ImageGeneration|null
	 * @throws Exception
	 */
	public function deleteImageGeneration(int $id): ?ImageGeneration {
		try {
			$imageGeneration = $this->getImageGeneration($id);
		} catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
			return null;
		}

		$generationId = $imageGeneration->getId();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('openai_i_url')
			->where(
				$qb->expr()->eq('generation_id', $qb->createNamedParameter($generationId, IQueryBuilder::PARAM_INT))
			);
		$qb->executeStatement();
		$qb->resetQueryParts();

		return $this->delete($imageGeneration);
	}

	/**
	 * @param int $maxAge
	 * @return int
	 * @throws Exception
	 */
	public function cleanupGenerations(int $maxAge = Application::MAX_GENERATION_IDLE_TIME): int {
		$ts = (new DateTime())->getTimestamp();
		$maxTimestamp = $ts - $maxAge;

		$qb = $this->db->getQueryBuilder();

		// get generations that will be deleted
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->lt('last_used_timestamp', $qb->createNamedParameter($maxTimestamp, IQueryBuilder::PARAM_INT))
			);

		/** @var ImageGeneration[] $generations */
		$generations = $this->findEntities($qb);
		$qb->resetQueryParts();

		/** @var array[] $fileNames */
		$fileNames = [];
		$fileIds = [];
		foreach ($generations as $generation) {
			$this->imageUrlMapper->deleteImageGenerationUrls($generation->getId());
		}

		// does not work
		// $this->imageUrlMapper->cleanupUrls($maxTimestamp);

		// delete generations
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->lt('last_used_timestamp', $qb->createNamedParameter($maxTimestamp, IQueryBuilder::PARAM_INT))
			);
		return $qb->executeStatement();
	}
}
