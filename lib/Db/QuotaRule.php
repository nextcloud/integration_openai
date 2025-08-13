<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use ReturnTypeWillChange;

/**
 * @method int getType()
 * @method void setType(int $type)
 * @method int getAmount()
 * @method void setAmount(int $amount)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method bool getPool()
 * @method void setPool(bool $pool)
 */
class QuotaRule extends Entity implements JsonSerializable {
	/** @var int */
	protected $type;
	/** @var int */
	protected $amount;
	/** @var int */
	protected $priority;
	/** @var bool */
	protected $pool;

	public function __construct() {
		$this->addType('type', 'integer');
		$this->addType('amount', 'integer');
		$this->addType('priority', 'integer');
		$this->addType('pool', 'boolean');
	}

	#[ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'type' => $this->type,
			'amount' => $this->amount,
			'priority' => $this->priority,
			'pool' => $this->pool
		];
	}
}
