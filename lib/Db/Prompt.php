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

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getType()
 * @method void setType(int $type)
 * @method string getValue()
 * @method void setValue(string $value)
 * @method int getTimestamp()
 * @method void setTimestamp(int $timestamp)
 */
class Prompt extends Entity implements \JsonSerializable {

	/** @var string */
	protected $userId;
	/** @var int */
	protected $type;
	/** @var string */
	protected $value;
	/** @var int */
	protected $timestamp;

	public function __construct() {
		$this->addType('user_id', 'string');
		$this->addType('type', 'integer');
		$this->addType('value', 'string');
		$this->addType('timestamp', 'integer');
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'user_id' => $this->userId,
			'type' => $this->type,
			'value' => $this->value,
			'timestamp' => $this->timestamp,
		];
	}
}
