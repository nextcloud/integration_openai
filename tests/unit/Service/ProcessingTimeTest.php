<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Unit tests for processing time tracking methods in OpenAiAPIService
 * Tests that text and image processing times are tracked independently and correctly
 */

namespace OCA\OpenAi\Tests\Unit\Service;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\QuotaRuleService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use Test\TestCase;

/**
 * @group DB
 */
class ProcessingTimeTest extends TestCase {
	private OpenAiAPIService $openAiApiService;
	private IAppConfig $appConfig;

	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = \OCP\Server::get(IAppConfig::class);

		// Reset processing times to known values
		$this->appConfig->setValueString(Application::APP_ID, 'openai_text_generation_time', '100', lazy: true);
		$this->appConfig->setValueString(Application::APP_ID, 'localai_text_generation_time', '100', lazy: true);
		$this->appConfig->setValueString(Application::APP_ID, 'openai_image_generation_time', '200', lazy: true);
		$this->appConfig->setValueString(Application::APP_ID, 'localai_image_generation_time', '200', lazy: true);

		$clientService = $this->createMock(IClientService::class);
		$openAiSettingsService = \OCP\Server::get(OpenAiSettingsService::class);

		$this->openAiApiService = new OpenAiAPIService(
			\OCP\Server::get(\Psr\Log\LoggerInterface::class),
			$this->createMock(\OCP\IL10N::class),
			$this->appConfig,
			\OCP\Server::get(ICacheFactory::class),
			\OCP\Server::get(QuotaUsageMapper::class),
			$openAiSettingsService,
			$this->createMock(\OCP\Notification\IManager::class),
			\OCP\Server::get(QuotaRuleService::class),
			$clientService,
		);
	}

	public function testGetExpTextProcessingTime(): void {
		// Test that we get the initial value we set
		$time = $this->openAiApiService->getExpTextProcessingTime();
		$this->assertEquals(100, $time, 'Initial text processing time should be 100');
	}

	public function testGetExpImgProcessingTime(): void {
		// Test that we get the initial value we set
		$time = $this->openAiApiService->getExpImgProcessingTime();
		$this->assertEquals(200, $time, 'Initial image processing time should be 200');
	}

	public function testUpdateExpTextProcessingTime(): void {
		// Get initial value
		$initialTime = $this->openAiApiService->getExpTextProcessingTime();
		$this->assertEquals(100, $initialTime);

		// Update with a new runtime
		$newRuntime = 150;
		$this->openAiApiService->updateExpTextProcessingTime($newRuntime);

		// Get updated value
		$updatedTime = $this->openAiApiService->getExpTextProcessingTime();

		// Calculate expected value using the lowpass filter formula
		// newTime = (1.0 - FACTOR) * oldTime + FACTOR * runtime
		$expectedTime = intval((1.0 - Application::EXPECTED_RUNTIME_LOWPASS_FACTOR) * 100.0 + Application::EXPECTED_RUNTIME_LOWPASS_FACTOR * 150.0);

		$this->assertEquals($expectedTime, $updatedTime, 'Text processing time should be updated using lowpass filter');
		$this->assertNotEquals($initialTime, $updatedTime, 'Text processing time should have changed');
	}

	public function testUpdateExpImgProcessingTime(): void {
		// Get initial value
		$initialTime = $this->openAiApiService->getExpImgProcessingTime();
		$this->assertEquals(200, $initialTime);

		// Update with a new runtime
		$newRuntime = 300;
		$this->openAiApiService->updateExpImgProcessingTime($newRuntime);

		// Get updated value
		$updatedTime = $this->openAiApiService->getExpImgProcessingTime();

		// Calculate expected value using the lowpass filter formula
		$expectedTime = intval((1.0 - Application::EXPECTED_RUNTIME_LOWPASS_FACTOR) * 200.0 + Application::EXPECTED_RUNTIME_LOWPASS_FACTOR * 300.0);

		$this->assertEquals($expectedTime, $updatedTime, 'Image processing time should be updated using lowpass filter');
		$this->assertNotEquals($initialTime, $updatedTime, 'Image processing time should have changed');
	}

	/**
	 * Regression test for bug where updateExpTextProcessingTime was calling getExpImgProcessingTime
	 * This test ensures that text and image processing times remain independent
	 */
	public function testTextAndImageProcessingTimesAreIndependent(): void {
		// Set distinct initial values
		$initialTextTime = $this->openAiApiService->getExpTextProcessingTime();
		$initialImgTime = $this->openAiApiService->getExpImgProcessingTime();

		$this->assertEquals(100, $initialTextTime, 'Initial text time should be 100');
		$this->assertEquals(200, $initialImgTime, 'Initial image time should be 200');

		// Update text processing time
		$this->openAiApiService->updateExpTextProcessingTime(500);

		// Verify that text time changed but image time did NOT change
		$newTextTime = $this->openAiApiService->getExpTextProcessingTime();
		$newImgTime = $this->openAiApiService->getExpImgProcessingTime();

		$this->assertNotEquals($initialTextTime, $newTextTime, 'Text processing time should have changed');
		$this->assertEquals($initialImgTime, $newImgTime, 'Image processing time should NOT have changed when updating text time');

		// Now update image processing time
		$this->openAiApiService->updateExpImgProcessingTime(600);

		// Verify that image time changed but text time stayed at its previous value
		$finalTextTime = $this->openAiApiService->getExpTextProcessingTime();
		$finalImgTime = $this->openAiApiService->getExpImgProcessingTime();

		$this->assertEquals($newTextTime, $finalTextTime, 'Text processing time should NOT have changed when updating image time');
		$this->assertNotEquals($newImgTime, $finalImgTime, 'Image processing time should have changed');
	}

	protected function tearDown(): void {
		// Clean up - reset to default values
		$this->appConfig->deleteKey(Application::APP_ID, 'openai_text_generation_time');
		$this->appConfig->deleteKey(Application::APP_ID, 'localai_text_generation_time');
		$this->appConfig->deleteKey(Application::APP_ID, 'openai_image_generation_time');
		$this->appConfig->deleteKey(Application::APP_ID, 'localai_image_generation_time');

		parent::tearDown();
	}
}
