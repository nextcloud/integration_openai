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

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getType()
 * @method void setType(int $type)
 * @method int getUnits()
 * @method void setUnits(int $units)
 * @method int getTimestamp()
 * @method void setTimestamp(int $timestamp)
 */
class QuotaUsage extends Entity implements \JsonSerializable {
	/** @var string */
	protected $userId;
	/** @var int */
	protected $type;
	/** @var int */
	protected $units;
	/** @var int */
	protected $timestamp;

	public function __construct() {
		$this->addType('user_id', 'string');
		$this->addType('type', 'integer');
		$this->addType('units', 'integer');
		$this->addType('timestamp', 'integer');
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'user_id' => $this->userId,
			'type' => $this->type,
			'units' => $this->units,
			'timestamp' => $this->timestamp,
		];
	}
}
