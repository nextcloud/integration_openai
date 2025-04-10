<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\Db;

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
