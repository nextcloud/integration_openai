<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 *
 * This unit test is designed to test the functionality of all providers
 * exposed by the app. It does not test the
 * actual watsonx.ai API calls, but rather mocks them.
 */

namespace OCA\Watsonx\Tests\Unit\Provider;

use OCA\Watsonx\AppInfo\Application;
use OCA\Watsonx\Db\QuotaUsageMapper;
use OCA\Watsonx\Service\WatsonxAPIService;
use OCA\Watsonx\Service\WatsonxSettingsService;
use OCA\Watsonx\TaskProcessing\ChangeToneProvider;
use OCA\Watsonx\TaskProcessing\HeadlineProvider;
use OCA\Watsonx\TaskProcessing\ProofreadProvider;
use OCA\Watsonx\TaskProcessing\SummaryProvider;
use OCA\Watsonx\TaskProcessing\TextToTextProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;
use Test\Util\User\Dummy;

/**
 * @group DB
 */
class WatsonxProviderTest extends TestCase {
	public const APP_NAME = 'integration_watsonx';
	public const TEST_USER1 = 'testuser';
	public const WATSONX_API_CHAT_ENDPOINT = Application::WATSONX_API_BASE_URL . '/ml/v1/text/chat?version=' . Application::WATSONX_API_VERSION;
	public const WATSONX_API_IAM_ENDPOINT = 'https://iam.cloud.ibm.com/identity/token';
	public const TEST_API_KEY = 'This is a PHPUnit test API key';
	public const TEST_ACCESS_TOKEN = 'This is a PHPUnit test access token';
	public const AUTHORIZATION_HEADER = 'Bearer ' . self::TEST_ACCESS_TOKEN;

	public const BASE_OPTIONS = [
		'headers' => [
			'Authorization' => self::AUTHORIZATION_HEADER,
			'User-Agent' => Application::USER_AGENT,
			'Content-Type' => 'application/json',
		],
	];

	public const TEXT_CHAT_RESPONSE = '{
		"id": "cmpl-15475d0dea9b4429a55843c77997f8a9",
		"model_id": "ibm/granite-3-8b-instruct",
		"created": 1689958352,
		"choices": [
			{
				"index": 0,
				"message": {
					"role": "assistant",
					"content": "This is a test response."
				},
				"finish_reason": "stop"
			}
		],
		"usage": {
			"completion_tokens": 12,
			"prompt_tokens": 9,
			"total_tokens": 21
		}
	}';

	public const IAM_OPTIONS = [
		'headers' => [
			'Content-Type' => 'application/x-www-form-urlencoded',
			'User-Agent' => Application::USER_AGENT,
		],
		'body' => [
			'grant_type' => 'urn:ibm:params:oauth:grant-type:apikey',
			'apikey' => self::TEST_API_KEY,
		],
	];

	public const IAM_RESPONSE = '{
		"access_token": "This is a PHPUnit test access token",
		"refresh_token": "not_supported",
		"ims_user_id": 11890,
		"token_type": "Bearer",
		"expires_in": 3600,
		"expiration": 1473188353,
		"scope": "ibm openid"
	}';

	private WatsonxAPIService $watsonxApiService;
	private WatsonxSettingsService $watsonxSettingsService;
	/**
	 * @var MockObject|IClient
	 */
	private $iClient;
	/**
	 * @var MockObject|IResponse
	 */
	private $iResponse;
	/**
	 * @var MockObject|IResponse
	 */
	private $iamResponse;
	private QuotaUsageMapper $quotaUsageMapper;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$backend = new Dummy();
		$backend->createUser(self::TEST_USER1, self::TEST_USER1);
		\OC::$server->get(\OCP\IUserManager::class)->registerBackend($backend);
	}

	protected function setUp(): void {
		parent::setUp();

		$this->loginAsUser(self::TEST_USER1);

		$this->watsonxSettingsService = \OC::$server->get(WatsonxSettingsService::class);

		$this->quotaUsageMapper = \OC::$server->get(QuotaUsageMapper::class);

		// We'll hijack the client service and subsequently iClient to return a mock response from the watsonx.ai API
		$clientService = $this->createMock(IClientService::class);
		$this->iClient = $this->createMock(IClient::class);
		$clientService->method('newClient')->willReturn($this->iClient);

		$this->watsonxApiService = new WatsonxAPIService(
			\OC::$server->get(\Psr\Log\LoggerInterface::class),
			$this->createMock(\OCP\IL10N::class),
			\OC::$server->get(IAppConfig::class),
			\OC::$server->get(ICacheFactory::class),
			\OC::$server->get(QuotaUsageMapper::class),
			$this->watsonxSettingsService,
			$clientService,
		);

		$this->iamResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$this->iamResponse->method('getBody')->willReturn(self::IAM_RESPONSE);
		$this->iamResponse->method('getStatusCode')->willReturn(200);

		$this->iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$this->iResponse->method('getBody')->willReturn(self::TEXT_CHAT_RESPONSE);
		$this->iResponse->method('getStatusCode')->willReturn(200);

		$this->watsonxSettingsService->setUserApiKey(self::TEST_USER1, self::TEST_API_KEY);
	}

	public static function tearDownAfterClass(): void {
		// Delete quota usage for test user
		$quotaUsageMapper = \OC::$server->get(QuotaUsageMapper::class);
		try {
			$quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
		} catch (\OCP\Db\Exception|\RuntimeException|\Exception|\Throwable $e) {
			// Ignore
		}

		$backend = new \Test\Util\User\Dummy();
		$backend->deleteUser(self::TEST_USER1);
		\OC::$server->get(\OCP\IUserManager::class)->removeBackend($backend);

		parent::tearDownAfterClass();
	}

	public function testFreePromptProvider(): void {
		$freePromptProvider = new TextToTextProvider(
			$this->watsonxApiService,
			\OC::$server->get(IAppConfig::class),
			$this->watsonxSettingsService,
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
			\OCP\Server::get(LoggerInterface::class),
		);

		$prompt = 'This is a test prompt';

		$options = self::BASE_OPTIONS;
		$options['body'] = json_encode([
			'model_id' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [
				[
					'role' => 'user',
					'content' => [['type' => 'text', 'text' => $prompt]],
				],
			],
			'n' => 1,
			'time_limit' => Application::WATSONX_DEFAULT_REQUEST_TIMEOUT * 1000,
			'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
		]);

		$this->iClient
			->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function ($url, $opts) use ($options) {
				static $invocationCount = 0;
				$invocationCount++;

				if ($invocationCount === 1) {
					$this->assertSame([self::WATSONX_API_IAM_ENDPOINT, self::IAM_OPTIONS], [$url, $opts]);
					return $this->iamResponse;
				}

				if ($invocationCount === 2) {
					$this->assertSame([self::WATSONX_API_CHAT_ENDPOINT, $options], [$url, $opts]);
					return $this->iResponse;
				}
			});

		$result = $freePromptProvider->process(self::TEST_USER1, ['input' => $prompt], fn () => null);
		$this->assertEquals('This is a test response.', $result['output']);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}


	public function testHeadlineProvider(): void {
		$headlineProvider = new HeadlineProvider(
			$this->watsonxApiService,
			\OC::$server->get(IAppConfig::class),
			$this->watsonxSettingsService,
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
		);

		$prompt = 'This is a test prompt';
		$message = 'Give me the headline of the following text in its original language. Do not output the language. Output only the headline without any quotes or additional punctuation.' . "\n\n" . $prompt;

		$options = self::BASE_OPTIONS;
		$options['body'] = json_encode([
			'model_id' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [
				[
					'role' => 'user',
					'content' => [['type' => 'text', 'text' => $message]],
				],
			],
			'n' => 1,
			'time_limit' => Application::WATSONX_DEFAULT_REQUEST_TIMEOUT * 1000,
			'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
		]);

		$this->iClient
			->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function ($url, $opts) use ($options) {
				static $invocationCount = 0;
				$invocationCount++;

				if ($invocationCount === 1) {
					$this->assertSame([self::WATSONX_API_IAM_ENDPOINT, self::IAM_OPTIONS], [$url, $opts]);
					return $this->iamResponse;
				}

				if ($invocationCount === 2) {
					$this->assertSame([self::WATSONX_API_CHAT_ENDPOINT, $options], [$url, $opts]);
					return $this->iResponse;
				}
			});

		$result = $headlineProvider->process(self::TEST_USER1, ['input' => $prompt], fn () => null);
		$this->assertEquals('This is a test response.', $result['output']);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testChangeToneProvider(): void {
		$changeToneProvider = new ChangeToneProvider(
			$this->watsonxApiService,
			\OC::$server->get(IAppConfig::class),
			$this->watsonxSettingsService,
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
		);

		$textInput = 'This is a test prompt';
		$toneInput = 'friendlier';
		$message = "Reformulate the following text in a $toneInput tone in its original language. Output only the reformulation. Here is the text:" . "\n\n" . $textInput . "\n\n" . 'Do not mention the used language in your reformulation. Here is your reformulation in the same language:';

		$options = self::BASE_OPTIONS;
		$options['body'] = json_encode([
			'model_id' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [
				[
					'role' => 'user',
					'content' => [['type' => 'text', 'text' => $message]],
				],
			],
			'n' => 1,
			'time_limit' => Application::WATSONX_DEFAULT_REQUEST_TIMEOUT * 1000,
			'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
		]);

		$this->iClient
			->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function ($url, $opts) use ($options) {
				static $invocationCount = 0;
				$invocationCount++;

				if ($invocationCount === 1) {
					$this->assertSame([self::WATSONX_API_IAM_ENDPOINT, self::IAM_OPTIONS], [$url, $opts]);
					return $this->iamResponse;
				}

				if ($invocationCount === 2) {
					$this->assertSame([self::WATSONX_API_CHAT_ENDPOINT, $options], [$url, $opts]);
					return $this->iResponse;
				}
			});

		$result = $changeToneProvider->process(self::TEST_USER1, ['input' => $textInput, 'tone' => $toneInput ], fn () => null);
		$this->assertEquals('This is a test response.', $result['output']);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}


	public function testSummaryProvider(): void {
		$summaryProvider = new SummaryProvider(
			$this->watsonxApiService,
			\OC::$server->get(IAppConfig::class),
			$this->watsonxSettingsService,
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
		);

		$prompt = 'This is a test prompt';
		$systemPrompt = 'Summarize the following text in the same language as the text.';

		$options = self::BASE_OPTIONS;
		$options['body'] = json_encode([
			'model_id' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [
				[
					'role' => 'system',
					'content' => $systemPrompt,
				],
				[
					'role' => 'user',
					'content' => [['type' => 'text', 'text' => $prompt]],
				],
			],
			'n' => 1,
			'time_limit' => Application::WATSONX_DEFAULT_REQUEST_TIMEOUT * 1000,
			'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
		]);

		$this->iClient
			->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function ($url, $opts) use ($options) {
				static $invocationCount = 0;
				$invocationCount++;

				if ($invocationCount === 1) {
					$this->assertSame([self::WATSONX_API_IAM_ENDPOINT, self::IAM_OPTIONS], [$url, $opts]);
					return $this->iamResponse;
				}

				if ($invocationCount === 2) {
					$this->assertSame([self::WATSONX_API_CHAT_ENDPOINT, $options], [$url, $opts]);
					return $this->iResponse;
				}
			});

		$result = $summaryProvider->process(self::TEST_USER1, ['input' => $prompt], fn () => null);
		$this->assertEquals('This is a test response.', $result['output']);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testProofreadProvider(): void {
		$proofreadProvider = new ProofreadProvider(
			$this->watsonxApiService,
			\OC::$server->get(IAppConfig::class),
			$this->watsonxSettingsService,
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
		);

		$prompt = 'This is a test prompt';
		$systemPrompt = 'Proofread the following text. List all spelling and grammar mistakes and how to correct them. Output only the list.';

		$options = self::BASE_OPTIONS;
		$options['body'] = json_encode([
			'model_id' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [
				[
					'role' => 'system',
					'content' => $systemPrompt,
				],
				[
					'role' => 'user',
					'content' => [['type' => 'text', 'text' => $prompt]],
				],
			],
			'n' => 1,
			'time_limit' => Application::WATSONX_DEFAULT_REQUEST_TIMEOUT * 1000,
			'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
		]);

		$this->iClient
			->expects($this->exactly(2))
			->method('post')
			->willReturnCallback(function ($url, $opts) use ($options) {
				static $invocationCount = 0;
				$invocationCount++;

				if ($invocationCount === 1) {
					$this->assertSame([self::WATSONX_API_IAM_ENDPOINT, self::IAM_OPTIONS], [$url, $opts]);
					return $this->iamResponse;
				}

				if ($invocationCount === 2) {
					$this->assertSame([self::WATSONX_API_CHAT_ENDPOINT, $options], [$url, $opts]);
					return $this->iResponse;
				}
			});

		$result = $proofreadProvider->process(self::TEST_USER1, ['input' => $prompt], fn () => null);
		$this->assertEquals('This is a test response.', $result['output']);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}
}
