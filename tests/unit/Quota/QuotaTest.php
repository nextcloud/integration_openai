<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 *
 * This unit test is designed to test the pool quota functionality
 * and the notifications for when quota is exceeded
 */

namespace OCA\OpenAi\Tests\Unit\Quota;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\EntityType;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\QuotaRuleService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Notification\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Test\TestCase;
use Test\Util\User\Dummy;
use Throwable;

/**
 * @group DB
 */
class QuotaTest extends TestCase {
	public const APP_NAME = 'integration_openai';
	public const TEST_USER1 = 'testuser1';
	public const TEST_USER2 = 'testuser2';

	private OpenAiAPIService $openAiApiService;
	private OpenAiSettingsService $openAiSettingsService;
	/**
	 * @var MockObject|IManager
	 */
	private $notificationManager;
	/**
	 * @var MockObject|ICacheFactory
	 */
	private $cacheFactory;
	private QuotaUsageMapper $quotaUsageMapper;
	private QuotaRuleService $quotaRuleService;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$backend = new Dummy();
		$backend->createUser(self::TEST_USER1, self::TEST_USER1);
		$backend->createUser(self::TEST_USER2, self::TEST_USER2);
		\OCP\Server::get(IUserManager::class)->registerBackend($backend);
	}

	protected function setUp(): void {
		parent::setUp();

		$this->loginAsUser(self::TEST_USER1);

		$this->openAiSettingsService = \OCP\Server::get(OpenAiSettingsService::class);

		$this->quotaUsageMapper = \OCP\Server::get(QuotaUsageMapper::class);

		$this->notificationManager = $this->createMock(IManager::class);

		$this->cacheFactory = $this->createMock(ICacheFactory::class);

		$this->quotaRuleService = \OCP\Server::get(QuotaRuleService::class);


		$this->openAiApiService = new OpenAiAPIService(
			\OCP\Server::get(LoggerInterface::class),
			$this->createMock(IL10N::class),
			\OCP\Server::get(IAppConfig::class),
			$this->cacheFactory,
			\OCP\Server::get(QuotaUsageMapper::class),
			$this->openAiSettingsService,
			$this->notificationManager,
			\OCP\Server::get(QuotaRuleService::class),
			\OCP\Server::get(IClientService::class),
		);
	}

	public static function tearDownAfterClass(): void {
		// Delete quota usage for test user
		$quotaUsageMapper = \OCP\Server::get(QuotaUsageMapper::class);
		try {
			$quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
		} catch (\OCP\Db\Exception|RuntimeException|Exception|Throwable $e) {
			// Ignore
		}
		$rules = \OCP\Server::get(QuotaRuleService::class)->getRules();
		foreach ($rules as $rule) {
			\OCP\Server::get(QuotaRuleService::class)->deleteRule($rule['id']);
		}

		$backend = new Dummy();
		$backend->deleteUser(self::TEST_USER1);
		$backend->deleteUser(self::TEST_USER2);
		\OCP\Server::get(IUserManager::class)->removeBackend($backend);

		parent::tearDownAfterClass();
	}
	public function testNotification(): void {
		$this->openAiSettingsService->setQuotas([1, 1, 1, 1]);
		$cache = $this->createMock(ICache::class);
		$this->cacheFactory->method('createLocal')->willReturn($cache);
		$key = 'quota_exceeded_' . self::TEST_USER1 . '_' . Application::QUOTA_TYPE_TEXT;

		$cache->expects($this->any())->method('get')->with($key)->willReturn($this->onConsecutiveCalls(null, true, true));
		$cache->expects($this->once())->method('set')->with($key, true, 3600);

		$this->notificationManager->expects($this->once())->method('notify');
		$this->assertFalse($this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_TEXT));
		$this->quotaUsageMapper->createQuotaUsage(self::TEST_USER1, Application::QUOTA_TYPE_TEXT, 100);

		// Send notification
		$this->assertTrue($this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_TEXT));
		// Try again to make sure a notification is only sent once
		$this->assertTrue($this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_TEXT));
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}
	public function testQuotaPool(): void {
		// Create a quota rule for both test users as a pool
		$this->openAiSettingsService->setQuotas([1000, 1, 1, 1]);
		$rule = $this->quotaRuleService->addRule();
		$rule['type'] = Application::QUOTA_TYPE_TEXT;
		$rule['amount'] = 10;
		$rule['pool'] = true;
		$rule['entities'] = [
			[
				'entity_id' => self::TEST_USER1,
				'entity_type' => EntityType::USER->value,
			],
			[
				'entity_id' => self::TEST_USER2,
				'entity_type' => EntityType::USER->value,
			]
		];
		$this->quotaRuleService->updateRule($rule['id'], $rule);

		$this->assertFalse($this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_TEXT));
		$this->quotaUsageMapper->createQuotaUsage(self::TEST_USER1, Application::QUOTA_TYPE_TEXT, 100, $rule['id']);

		$this->assertTrue($this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_TEXT));
		// Check other user
		$this->assertTrue($this->openAiApiService->isQuotaExceeded(self::TEST_USER2, Application::QUOTA_TYPE_TEXT));
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER2);
	}

}
