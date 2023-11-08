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
 * @method string getHash()
 * @method void setHash(string $hash)
 * @method string getPrompt()
 * @method void setPrompt(string $prompt)
 * @method int getLastUsedTimestamp()
 * @method void setLastUsedTimestamp(int $lastUsedTimestamp)
 */
class ImageGeneration extends Entity implements \JsonSerializable {
	/** @var string */
	protected $hash;
	/** @var string */
	protected $prompt;
	/** @var int */
	protected $lastUsedTimestamp;

	public function __construct() {
		$this->addType('hash', 'string');
		$this->addType('prompt', 'string');
		$this->addType('last_used_timestamp', 'integer');
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'hash' => $this->hash,
			'prompt' => $this->prompt,
			'last_used_timestamp' => $this->lastUsedTimestamp,
		];
	}
}
