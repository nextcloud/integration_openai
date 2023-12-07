<?php

// SPDX-FileCopyrightText: Sami Finnilä <sami.finnila@nextcloud.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * This unit test is designed to test the functionality of all providers
 * exposed by the app. It does not test the
 * actual openAI/LocalAI api calls, but rather mocks them.
 */

namespace OCA\OpenAi\Tests\Unit\Translation;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\ImageGenerationMapper;
use OCA\OpenAi\Db\ImageUrlMapper;
use OCA\OpenAi\Db\PromptMapper;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\TextProcessing\FreePromptProvider;
use OCA\OpenAi\TextProcessing\HeadlineProvider;
use OCA\OpenAi\TextProcessing\ReformulateProvider;
use OCA\OpenAi\TextProcessing\SummaryProvider;
use OCA\OpenAi\Translation\TranslationProvider;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Test\TestCase;

/**
 * @group DB
 */
class OpenAiProviderTest extends TestCase {
	public const APP_NAME = 'integration_openai';
	public const TEST_USER1 = 'testuser';
	public const OPENAI_API_BASE = 'https://api.openai.com/v1/';

	private $openAiApiService;
	private $openAiSettingsService;
	private $iClient;
	private $quotaUsageMapper;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$backend = new \Test\Util\User\Dummy();
		$backend->createUser(self::TEST_USER1, self::TEST_USER1);
		\OC::$server->get(\OCP\IUserManager::class)->registerBackend($backend);
	}

	protected function setUp(): void {
		parent::setUp();

		$this->loginAsUser(self::TEST_USER1);

		$this->openAiSettingsService = \OC::$server->get(OpenAiSettingsService::class);

		$this->quotaUsageMapper = \OC::$server->get(QuotaUsageMapper::class);

		// We'll hijack the client service and subsequently iClient to return a mock response from the OpenAI API
		$clientService = $this->createMock(IClientService::class);
		$this->iClient = $this->createMock(\OCP\Http\Client\IClient::class);
		$clientService->method('newClient')->willReturn($this->iClient);

		$this->openAiApiService = new OpenAiAPIService(
			\OC::$server->get(\Psr\Log\LoggerInterface::class),
			$this->createMock(\OCP\IL10N::class),
			\OC::$server->get(IConfig::class),
			\OC::$server->get(ImageGenerationMapper::class),
			\OC::$server->get(ImageUrlMapper::class),
			\OC::$server->get(PromptMapper::class),
			\OC::$server->get(QuotaUsageMapper::class),
			$this->openAiSettingsService,
			$clientService,
		);

		$this->openAiSettingsService->setUserApiKey(self::TEST_USER1, 'This is a PHPUnit test API key');
	}

	public static function tearDownAfterClass(): void {
		$promptMapper = \OC::$server->get(PromptMapper::class);
		try {
			$promptMapper->deleteUserPrompts(self::TEST_USER1);
		} catch (\OCP\Db\Exception | \Exception | \Throwable $e) {
			// Ignore
		}
		
		// Delete quota usage for test user
		$quotaUsageMapper = \OC::$server->get(QuotaUsageMapper::class);
		try {
			$quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
		} catch (\OCP\Db\Exception | \RuntimeException | \Exception | \Throwable $e) {
			// Ignore
		}
		
		$backend = new \Test\Util\User\Dummy();
		$backend->deleteUser(self::TEST_USER1);
		\OC::$server->get(\OCP\IUserManager::class)->removeBackend($backend);

		parent::tearDownAfterClass();
	}

	public function testFreePromptProvider(): void {
		$freePromptProvider = new FreePromptProvider(
			$this->openAiApiService,
			\OC::$server->get(IConfig::class),
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
			$this->openAiSettingsService);

		$prompt = 'This is a test prompt';
		$n = 1;

		$response = '{
			"id": "chatcmpl-123",
			"object": "chat.completion",
			"created": 1677652288,
			"model": "gpt-3.5-turbo-0613",
			"system_fingerprint": "fp_44709d6fcb",
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
			  "prompt_tokens": 9,
			  "completion_tokens": 12,
			  "total_tokens": 21
			}
		  }';

		$url = self::OPENAI_API_BASE . 'chat/completions';
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => 'Nextcloud OpenAI integration', 'Authorization' => 'Bearer This is a PHPUnit test API key', 'Content-Type' => 'application/json']];
		$options['body'] = json_encode(['model' => Application::DEFAULT_COMPLETION_MODEL_ID, 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS, 'n' => $n, 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $freePromptProvider->process($prompt);
		$this->assertEquals('This is a test response.', $result);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}


	public function testHeadlineProvider(): void {
		$headlineProvider = new HeadlineProvider(
			$this->openAiApiService,
			\OC::$server->get(IConfig::class),
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
			$this->openAiSettingsService);

		$prompt = 'This is a test prompt';
		$n = 1;

		$response = '{
			"id": "chatcmpl-123",
			"object": "chat.completion",
			"created": 1677652288,
			"model": "gpt-3.5-turbo-0613",
			"system_fingerprint": "fp_44709d6fcb",
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
			  "prompt_tokens": 9,
			  "completion_tokens": 12,
			  "total_tokens": 21
			}
		}';
		
		$url = self::OPENAI_API_BASE . 'chat/completions';

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => 'Nextcloud OpenAI integration', 'Authorization' => 'Bearer This is a PHPUnit test API key', 'Content-Type' => 'application/json']];
		$options['body'] = json_encode(['model' => Application::DEFAULT_COMPLETION_MODEL_ID, 'messages' => [['role' => 'user', 'content' => 'Give me the headline of the following text:' . "\n\n" . $prompt]], 'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS, 'n' => $n, 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $headlineProvider->process($prompt);
		$this->assertEquals('This is a test response.', $result);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testReformulateProvider(): void {
		$reformulateProvider = new ReformulateProvider(
			$this->openAiApiService,
			\OC::$server->get(IConfig::class),
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
			$this->openAiSettingsService);

		$prompt = 'This is a test prompt';
		$n = 1;

		$response = '{
            "id": "chatcmpl-123",
            "object": "chat.completion",
            "created": 1677652288,
            "model": "gpt-3.5-turbo-0613",
            "system_fingerprint": "fp_44709d6fcb",
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
              "prompt_tokens": 9,
              "completion_tokens": 12,
              "total_tokens": 21
            }
        }';

		$url = self::OPENAI_API_BASE . 'chat/completions';

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => 'Nextcloud OpenAI integration', 'Authorization' => 'Bearer This is a PHPUnit test API key', 'Content-Type' => 'application/json']];
		$options['body'] = json_encode(['model' => Application::DEFAULT_COMPLETION_MODEL_ID, 'messages' => [['role' => 'user', 'content' => 'Reformulate the following text:' . "\n\n" . $prompt]], 'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS, 'n' => $n, 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $reformulateProvider->process($prompt);
		$this->assertEquals('This is a test response.', $result);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testSummaryProvider(): void {
		$summaryProvider = new SummaryProvider(
			$this->openAiApiService,
			\OC::$server->get(IConfig::class),
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
			$this->openAiSettingsService);

		$prompt = 'This is a test prompt';
		$n = 1;

		$response = '{
            "id": "chatcmpl-123",
            "object": "chat.completion",
            "created": 1677652288,
            "model": "gpt-3.5-turbo-0613",
            "system_fingerprint": "fp_44709d6fcb",
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
              "prompt_tokens": 9,
              "completion_tokens": 12,
              "total_tokens": 21
            }
        }';

		$url = self::OPENAI_API_BASE . 'chat/completions';

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => 'Nextcloud OpenAI integration', 'Authorization' => 'Bearer This is a PHPUnit test API key', 'Content-Type' => 'application/json']];
		$options['body'] = json_encode(['model' => Application::DEFAULT_COMPLETION_MODEL_ID, 'messages' => [['role' => 'user', 'content' => 'Summarize the following text:' . "\n\n" . $prompt]], 'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS, 'n' => $n, 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $summaryProvider->process($prompt);
		$this->assertEquals('This is a test response.', $result);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testTranslationProvider(): void {
		$translationProvider = new TranslationProvider(
			$this->createMock(\OCP\ICacheFactory::class),
			\OC::$server->get(\OCP\L10N\IFactory::class),
			$this->openAiApiService,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OC::$server->get(IConfig::class),
			self::TEST_USER1,
			$this->openAiSettingsService);

		$prompt = 'This is a test prompt';
		$n = 1;
		$fromLang = 'Swedish';
		$toLang = 'en';

		$response = '{
            "id": "chatcmpl-123",
            "object": "chat.completion",
            "created": 1677652288,
            "model": "gpt-3.5-turbo-0613",
            "system_fingerprint": "fp_44709d6fcb",
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
              "prompt_tokens": 9,
              "completion_tokens": 12,
              "total_tokens": 21
            }
        }';

		$url = self::OPENAI_API_BASE . 'chat/completions';

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => 'Nextcloud OpenAI integration', 'Authorization' => 'Bearer This is a PHPUnit test API key', 'Content-Type' => 'application/json']];
		$options['body'] = json_encode(['model' => Application::DEFAULT_COMPLETION_MODEL_ID, 'messages' => [['role' => 'user', 'content' => 'Translate from ' . $fromLang . ' to English (US): ' . $prompt]], 'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS, 'n' => $n, 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $translationProvider->translate($fromLang, $toLang, $prompt);
		$this->assertEquals('This is a test response.', $result);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

}
