<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\EntityType;
use OCA\OpenAi\Db\QuotaRuleMapper;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCA\OpenAi\Db\QuotaUserMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class QuotaRuleService {
	public function __construct(
		private QuotaRuleMapper $quotaRuleMapper,
		private QuotaUserMapper $quotaUserMapper,
		private OpenAiSettingsService $openAiSettingsService,
		private IGroupManager $groupManager,
		private ICacheFactory $cacheFactory,
		private IUserManager $userManager,
		private QuotaUsageMapper $quotaUsageMapper,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Returns the quota rule for the given user
	 *
	 * @param int $quotaType
	 * @param string $userId It can be an empty string
	 * @return array
	 */
	public function getRule(int $quotaType, string $userId) {
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		$cacheKey = Application::QUOTA_RULES_CACHE_PREFIX . $quotaType . '-' . $userId;
		$rule = $cache->get($cacheKey);
		if ($rule === null) {
			try {
				$user = $this->userManager->get($userId);
				if ($user === null) {
					// re-use the db exception for not found users
					throw new DoesNotExistException('User not found: ' . $userId);
				}
				$groups = $this->groupManager->getUserGroupIds($user);
				$rule = $this->quotaRuleMapper->getRule($quotaType, $userId, $groups)->jsonSerialize();
			} catch (DoesNotExistException|MultipleObjectsReturnedException) {
				$rule = [
					'amount' => $this->openAiSettingsService->getQuotas()[$quotaType],
					'pool' => false,
					'id' => null,
				];
			}
			$cache->set($cacheKey, $rule);
		}
		return $rule;
	}

	/**
	 * Clears the cache for the quota rules
	 */
	public function clearCache(): void {
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		$cache->clear(Application::QUOTA_RULES_CACHE_PREFIX);
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	public function getRules(): array {
		$rules = $this->quotaRuleMapper->getRules();
		$userManager = $this->userManager;
		return array_map(function ($rule) use ($userManager) {
			$entities = $this->quotaUserMapper->getUsers($rule->getId());
			$result = $rule->jsonSerialize();
			$result['entities'] = array_map(static function ($u) use ($userManager) {
				$displayName = $u->getEntityId();
				if ($u->getEntityType() === EntityType::USER->value) {
					$user = $userManager->get($u->getEntityId());
					$displayName = $user->getDisplayName();
				}
				return [
					'display_name' => $displayName,
					'entity_type' => $u->getEntityType(),
					'entity_id' => $u->getEntityId(),
					'id' => $u->getEntityType() . '-' . $u->getEntityId(),
				];
			}, $entities);
			$result['pool'] = $result['pool'] === 1;
			return $result;
		}, $rules);
	}

	/**
	 * @return array created rule with entities
	 * @throws Exception
	 */
	public function addRule(): array {
		$id = $this->quotaRuleMapper->addRule(0, 0, 0, 0);
		$this->clearCache();
		return [
			'id' => $id,
			'type' => 0,
			'amount' => 0,
			'priority' => 0,
			'pool' => false,
			'entities' => [],
		];
	}

	/**
	 * @param int $id
	 * @param array $rule
	 * @return array updated rule with entities
	 * @throws Exception
	 */
	public function updateRule(int $id, array $rule): array {
		$this->validateRuleBasics($rule);
		$this->validateEntities($rule['entities']);
		$this->quotaRuleMapper->updateRule($id, $rule['type'], $rule['amount'], $rule['priority'], $rule['pool']);
		$this->quotaUserMapper->setUsers($id, $rule['entities']);
		$rule['id'] = $id;
		$this->clearCache();
		return $rule;
	}

	/**
	 * @param int $id
	 * @throws Exception
	 */
	public function deleteRule(int $id): void {
		$this->quotaUserMapper->deleteByRuleId($id);
		$this->quotaRuleMapper->deleteRule($id);
		$this->clearCache();
	}

	/**
	 * Validate the basic parts of a quota rule: type and amount
	 *
	 * @param array $rule with keys 'type' and 'amount'
	 *
	 * @throws Exception if the type is invalid or the amount is less than 0
	 */
	private function validateRuleBasics(array $rule): void {
		$validTypes = [
			Application::QUOTA_TYPE_TEXT,
			Application::QUOTA_TYPE_IMAGE,
			Application::QUOTA_TYPE_TRANSCRIPTION,
			Application::QUOTA_TYPE_SPEECH,
		];
		if (!in_array($rule['type'], $validTypes, true)) {
			throw new Exception('Invalid quota type');
		}
		if ($rule['amount'] < 0) {
			throw new Exception('Amount must be >= 0');
		}
	}

	/**
	 * Validate the entities of a quota rule
	 *
	 * @param array $entities contains each entity as an array with keys 'entity_type' and 'entity_id'
	 *
	 * @throws Exception if an entity is invalid
	 */
	private function validateEntities(array $entities) {
		foreach ($entities as $e) {
			if (!is_array($e)) {
				$this->logger->warning('Invalid entity', $e);
				throw new Exception('Invalid entity');
			}
			if (!isset($e['entity_type'], $e['entity_id']) || EntityType::tryFrom($e['entity_type']) === null || !is_string($e['entity_id'])) {
				$this->logger->warning('Invalid entity', $e);
				throw new Exception('Invalid entity');
			}
		}
	}
	public function getQuotaUsage(int $startDate, int $endDate, int $type): array {
		$data = [[$this->l10n->t('Name'), $this->l10n->t('Usage')]];
		$users = $this->quotaUsageMapper->getUsersQuotaUsage($startDate, $endDate, $type);
		$pools = $this->quotaUsageMapper->getPoolsQuotaUsage($startDate, $endDate, $type);
		$usersIdx = 0;
		$poolsIdx = 0;
		while ($usersIdx < count($users) && $poolsIdx < count($pools)) {
			if ($users[$usersIdx]['usage'] > $pools[$poolsIdx]['usage']) {
				$data[] = [$users[$usersIdx]['user_id'], $users[$usersIdx]['usage']];
				$usersIdx++;
			} else {
				$data[] = [$this->l10n->t('Quota pool for rule %d', $pools[$poolsIdx]['pool']), $pools[$poolsIdx]['usage']];
				$poolsIdx++;
			}
		}
		while ($usersIdx < count($users)) {
			$data[] = [$users[$usersIdx]['user_id'], $users[$usersIdx]['usage']];
			$usersIdx++;
		}
		while ($poolsIdx < count($pools)) {
			$data[] = [$this->l10n->t('Quota pool for rule %d', $pools[$poolsIdx]['pool']), $pools[$poolsIdx]['usage']];
			$poolsIdx++;
		}
		return $data;
	}
}
