<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use ReturnTypeWillChange;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getType()
 * @method void setType(int $type)
 * @method int getUnits()
 * @method void setUnits(int $units)
 * @method int getTimestamp()
 * @method void setTimestamp(int $timestamp)
 * @method int getPool()
 * @method void setPool(int $pool)
 */
class QuotaUsage extends Entity implements JsonSerializable {
	/** @var string */
	protected $userId;
	/** @var int */
	protected $type;
	/** @var int */
	protected $units;
	/** @var int */
	protected $timestamp;
	/** @var int */
	protected $pool;

	public function __construct() {
		$this->addType('user_id', Types::STRING);
		$this->addType('type', Types::INTEGER);
		$this->addType('units', Types::INTEGER);
		$this->addType('timestamp', Types::INTEGER);
		$this->addType('pool', Types::INTEGER);
	}

	#[ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->getId(),
			'user_id' => $this->getUserId(),
			'type' => $this->getType(),
			'units' => $this->getUnits(),
			'timestamp' => $this->getTimestamp(),
			'pool' => $this->getPool()
		];
	}
}
