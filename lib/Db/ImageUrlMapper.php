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
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
/**
 * @extends QBMapper<ImageUrl>
 */
class ImageUrlMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'openai_i_url', ImageUrl::class);
	}

	/**
	 * @param int $generationId
	 * @return array<ImageUrl>
	 * @throws Exception
	 */
	public function getImageUrlsOfGeneration(int $generationId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('generation_id', $qb->createNamedParameter($generationId, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntities($qb);
	}

	/**
	 * @param int $generationId
	 * @param int $urlId
	 * @return ImageUrl
	 * @throws Exception
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function getImageUrlOfGeneration(int $generationId, int $urlId): ImageUrl {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('generation_id', $qb->createNamedParameter($generationId, IQueryBuilder::PARAM_INT))
			)
			->andWhere(
				$qb->expr()->eq('id', $qb->createNamedParameter($urlId, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity($qb);
	}

	/**
	 * @param int $generationId
	 * @param string $url
	 * @return ImageUrl
	 * @throws Exception
	 */
	public function createImageUrl(int $generationId, string $url): ImageUrl {
		$imageUrl = new ImageUrl();
		$imageUrl->setGenerationId($generationId);
		$imageUrl->setUrl($url);
		return $this->insert($imageUrl);
	}

	/**
	 * @param int $generationId
	 * @return void
	 * @throws Exception
	 */
	public function deleteImageGenerationUrls(int $generationId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('openai_i_url')
			->where(
				$qb->expr()->eq('generation_id', $qb->createNamedParameter($generationId, IQueryBuilder::PARAM_INT))
			);
		$qb->executeStatement();
	}

	/**
	 * @param int $maxAge
	 * @return int
	 * @throws Exception
	 */
	public function cleanupUrls(int $maxAge = Application::MAX_GENERATION_IDLE_TIME): int {
		$ts = (new DateTime())->getTimestamp();
		$maxTimestamp = $ts - $maxAge;

		$qb = $this->db->getQueryBuilder();

		// this does not work. is it even possible to do this? it is done in
		// https://github.com/nextcloud/mail/blob/main/lib/Db/AliasMapper.php#L124-L129
		/*
		$qb->delete($this->getTableName(), 'aliases')
			->innerJoin('aliases', 'openai_i_gen', 'gen', $qb->expr()->eq('gen.id', 'url.generation_id'))
			->where(
				$qb->expr()->lt('gen.last_used_timestamp', $qb->createNamedParameter($maxTimestamp, IQueryBuilder::PARAM_INT)),
			);
		*/

		return $qb->executeStatement();
	}
}
