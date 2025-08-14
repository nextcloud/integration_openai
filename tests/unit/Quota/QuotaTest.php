<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 *
 * This unit test is designed to test the functionality of all providers
 * exposed by the app. It does not test the
 * actual openAI/LocalAI api calls, but rather mocks them.
 */

namespace OCA\OpenAi\Tests\Unit\Quota;

use Exception;
use OC;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
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

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$backend = new Dummy();
		$backend->createUser(self::TEST_USER1, self::TEST_USER1);
		OC::$server->get(IUserManager::class)->registerBackend($backend);
	}

	protected function setUp(): void {
		parent::setUp();

		$this->loginAsUser(self::TEST_USER1);

		$this->openAiSettingsService = OC::$server->get(OpenAiSettingsService::class);

		$this->quotaUsageMapper = OC::$server->get(QuotaUsageMapper::class);

		$this->notificationManager = $this->createMock(IManager::class);

		$this->cacheFactory = $this->createMock(ICacheFactory::class);


		$this->openAiApiService = new OpenAiAPIService(
			OC::$server->get(LoggerInterface::class),
			$this->createMock(IL10N::class),
			OC::$server->get(IAppConfig::class),
			$this->cacheFactory,
			OC::$server->get(QuotaUsageMapper::class),
			$this->openAiSettingsService,
			$this->notificationManager,
			OC::$server->get(IClientService::class),
		);
	}

	public static function tearDownAfterClass(): void {
		// Delete quota usage for test user
		$quotaUsageMapper = OC::$server->get(QuotaUsageMapper::class);
		try {
			$quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
		} catch (\OCP\Db\Exception|RuntimeException|Exception|Throwable $e) {
			// Ignore
		}

		$backend = new Dummy();
		$backend->deleteUser(self::TEST_USER1);
		OC::$server->get(IUserManager::class)->removeBackend($backend);

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

}
