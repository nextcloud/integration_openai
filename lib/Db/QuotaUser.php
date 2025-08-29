<?php

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
 * @method int getRuleId()
 * @method void setRuleId(int $ruleId)
 * @method int getEntityType()
 * @method void setEntityType(int $entityType)
 * @method string getEntityId()
 * @method void setEntityId(string $entityId)
 */
class QuotaUser extends Entity implements JsonSerializable {
	/** @var int */
	protected $ruleId;
	/** @var int */
	protected $entityType;
	/** @var string */
	protected $entityId;

	public function __construct() {
		$this->addType('rule_id', Types::INTEGER);
		$this->addType('entity_type', Types::INTEGER);
		$this->addType('entity_id', Types::STRING);
	}

	#[ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->getId(),
			'rule_id' => $this->getRuleId(),
			'entity_type' => $this->getEntityType(),
			'entity_id' => $this->getEntityId()
		];
	}
}
