<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use ReturnTypeWillChange;

/**
 * @method int getRuleId()
 * @method void setRuleId(int $ruleId)
 * @method string getEntityType()
 * @method void setEntityType(string $entityType)
 * @method string getEntityId()
 * @method void setEntityId(string $entityId)
 */
class QuotaUser extends Entity implements JsonSerializable {
	/** @var int */
	protected $ruleId;
	/** @var string */
	protected $entityType;
	/** @var string */
	protected $entityId;

	public function __construct() {
		$this->addType('rule_id', 'integer');
		$this->addType('entity_type', 'string');
		$this->addType('entity_id', 'string');
	}

	#[ReturnTypeWillChange]
	public function jsonSerialize() {
		return [
			'id' => $this->id,
			'rule_id' => $this->ruleId,
			'entity_type' => $this->entityType,
			'entity_id' => $this->entityId
		];
	}
}
