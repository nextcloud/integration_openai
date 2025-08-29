<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use ReturnTypeWillChange;

/**
 * @method int getType()
 * @method void setType(int $type)
 * @method int getAmount()
 * @method void setAmount(int $amount)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method int getPool()
 * @method void setPool(int $pool)
 */
class QuotaRule extends Entity implements JsonSerializable {
	/** @var int */
	protected $type;
	/** @var int */
	protected $amount;
	/** @var int */
	protected $priority;
	/** @var int */
	protected $pool;

	public function __construct() {
		$this->addType('type', Types::INTEGER);
		$this->addType('amount', Types::INTEGER);
		$this->addType('priority', Types::INTEGER);
		$this->addType('pool', Types::INTEGER);
	}

	#[ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->getId(),
			'type' => $this->getType(),
			'amount' => $this->getAmount(),
			'priority' => $this->getPriority(),
			'pool' => $this->getPool()
		];
	}
}
