<?php

// SPDX-FileCopyrightText: Sami Finnilä <sami.finnila@nextcloud.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * This unit test is designed to test the functionality of the app from the
 * controller all the way to the database and back. It does not test the
 * actual openAI/LocalAI api calls, but rather mocks them.
 */


namespace OCA\OpenAi\Tests\Controller;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Controller\OpenAiAPIController;
use OCA\OpenAi\Db\ImageGenerationMapper;
use OCA\OpenAi\Db\ImageUrlMapper;
use OCA\OpenAi\Db\PromptMapper;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Test\TestCase;

/**
 * @group DB
 */
class OpenAiControllerTest extends TestCase {
	public const APP_NAME = 'integration_openai';
	public const TEST_USER1 = 'testuser';
	public const OPENAI_API_BASE = 'https://api.openai.com/v1/';
	public const AUTHORIZATION_HEADER = 'Bearer This is a PHPUnit test API key';

	private $openAiApiController;
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

		$this->openAiApiService = new OpenAiAPIService( //\OC::$server->get(OpenAiAPIService::class);
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

		$this->openAiApiController = new OpenAiAPIController( //\OC::$server->get(OpenAiAPIController::class);
			self::APP_NAME,
			$this->createMock(\OCP\IRequest::class),
			$this->openAiApiService,
			$this->openAiSettingsService,
			$this->createMock(\OCP\AppFramework\Services\IInitialState::class),
			self::TEST_USER1,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(\OCP\IL10N::class)
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
		// Reset settings
		$settingsService = \OC::$server->get(OpenAiSettingsService::class);
		$settingsService->setServiceUrl('');
		$settingsService->setUseBasicAuth(false);
		$settingsService->setChatEndpointEnabled(false);

		// Set all quotas to zero
		$quotas = $settingsService->getQuotas();
		foreach ($quotas as $key => $quota) {
			$quotas[$key] = 0;
		}
		$settingsService->setQuotas($quotas);
		parent::tearDownAfterClass();
	}


	public function testGetModels() {
		$this->openAiSettingsService->setServiceUrl('');
		$response = '{
            "object": "list",
            "data": [
              {
                "id": "model-id-0",
                "object": "model",
                "created": 1686935002,
                "owned_by": "organization-owner"
              },
              {
                "id": "model-id-1",
                "object": "model",
                "created": 1686935002,
                "owned_by": "organization-owner"
              },
              {
                "id": "model-id-2",
                "object": "model",
                "created": 1686935002,
                "owned_by": "openai"
              }
            ]
          }';

		$url = self::OPENAI_API_BASE . 'models';
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('get')->with($url, $options)->willReturn($iResponse);

		$response = $this->openAiApiController->getModels();

		$this->assertEquals(200, $response->getStatus());
		$this->assertCount(3, $response->getData());
		$this->assertEquals('model-id-0', $response->getData()['data'][0]['id']);
		$this->assertEquals('model-id-1', $response->getData()['data'][1]['id']);
		$this->assertEquals('model-id-2', $response->getData()['data'][2]['id']);
		$this->assertArrayHasKey('default_completion_model_id', $response->getData());
	}

	public function testCreateCompletionWithOpenAi(bool $clearQuota = true) {
		$this->openAiSettingsService->setServiceUrl('');
		$prompt = 'This is a test prompt';
		$n = 1;
		$maxTokens = 42;

		$response = '{
            "id": "cmpl-123",
            "object": "text_completion",
            "created": 1612429630,
            "model": "davinci:2020-05-03",
            "choices": [
              {
                "text": "This is a test result",
                "index": 0,
                "logprobs": null,
                "finish_reason": "length"
              }
            ],
            "usage": {
              "prompt_tokens": 42,
              "completion_tokens": 42,
              "total_tokens": 42
            }
          }';

		$url = self::OPENAI_API_BASE . 'completions';
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$options['body'] = json_encode(['model' => 'test_model', 'prompt' => $prompt, 'max_tokens' => $maxTokens, 'n' => $n, 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$response = $this->openAiApiController->createCompletion($prompt, $n, "test_model", $maxTokens);

		$this->assertEquals(200, $response->getStatus());
		$this->assertEquals('This is a test result', $response->getData()[0]);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(42, $usage);
		// Clear quota usage
		if ($clearQuota) {
			$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
		}
	}

	public function testCreateChatCompletionWithOpenAi() {
		$this->openAiSettingsService->setServiceUrl('');
		$prompt = 'This is a test prompt';
		$n = 1;
		$maxTokens = 42;
		$model = 'gpt-3.5-turbo-0613';

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
				  "content": "Hello there, how may I assist you today?"
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
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$options['body'] = json_encode(['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => $maxTokens, 'n' => $n, 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$response = $this->openAiApiController->createCompletion($prompt, $n, $model, $maxTokens);

		$this->assertEquals(200, $response->getStatus());
		$this->assertEquals('Hello there, how may I assist you today?', $response->getData()[0]);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testTranscribe(bool $clearQuota = true) {
		$this->openAiSettingsService->setServiceUrl('');
		$audioStringDecoded = bin2hex(random_bytes(32));
		$audioString = 'data:audio/mp3;base64,' . base64_encode($audioStringDecoded);

		$response = '{
			"task": "transcribe",
			"language": "english",
			"duration": 19.79,
			"segments": [
				{
					"id": 0,
					"seek": 0,
					"start": 0.0,
					"end": 8.64,
					"text": " Hello, this is a test recording about GPT-3, DALL-E and other OpenAI models.",
					"tokens": [
						2425,
						11,
						341,
						307,
						257,
						1500,
						6613,
						466,
						26039,
						51,
						12,
						18,
						11,
						413,
						15921,
						12,
						36,
						293,
						661,
						7238,
						48698,
						5245,
						13
					],
					"temperature": 0.0,
					"avg_logprob": -0.2631749353910747,
					"compression_ratio": 1.2857142857142858,
					"no_speech_prob": 0.17765560746192932,
					"transient": false
				},
				{
					"id": 1,
					"seek": 0,
					"start": 8.64,
					"end": 10.0,
					"text": " Testing.",
					"tokens": [
						286,
						478						
					],
					"temperature": 0.0,
					"avg_logprob": -0.2631749353910747,
					"compression_ratio": 1.2857142857142858,
					"no_speech_prob": 0.17765560746192932,
					"transient": false
				}				
			],
			"text": "Hello, this is a test recording about GPT-3, DALL-E and other OpenAI models. Testing."
		}';

		$url = self::OPENAI_API_BASE . 'audio/translations';
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER]];
		$options['multipart'] = [
			[
				'name' => 'model',
				'contents' => Application::DEFAULT_TRANSCRIPTION_MODEL_ID,
			],
			[
				'name' => 'file',
				'contents' => $audioStringDecoded,
				'filename' => 'file.mp3'
			],
			[
				'name' => 'response_format',
				'contents' => 'verbose_json',
			],
		];

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$response = $this->openAiApiController->transcribe($audioString, true);

		$this->assertEquals(200, $response->getStatus());
		$this->assertEquals('Hello, this is a test recording about GPT-3, DALL-E and other OpenAI models. Testing.', $response->getData()['text']);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TRANSCRIPTION);
		$this->assertEquals(10, $usage);
		// Clear quota usage
		if ($clearQuota) {
			$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
		}
	}

	public function testImageGenerationWithOpenAi(bool $clearQuota = true) {
		$this->openAiSettingsService->setServiceUrl('');
		$prompt = 'This is a test prompt';
		$n = 2;

		// We need random urls so that there's no hash collisions
		$url1 = 'https://image1.net' . bin2hex(random_bytes(32));
		$url2 = 'https://image1.net' . bin2hex(random_bytes(32));

		$response = '{
			"created": 1589478378,
			"data": [
			  {
				"url": "' . $url1 . '"
			  },
			  {
				"url": "' . $url2 . '"
			  }
			]
		  }';

		$url = self::OPENAI_API_BASE . 'images/generations';
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$options['body'] = json_encode(['prompt' => $prompt,'size' => Application::DEFAULT_IMAGE_SIZE,'model' => 'dall-e-2', 'n' => $n, 'response_format' => 'url', 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$response = $this->openAiApiController->createImage($prompt, $n, Application::DEFAULT_IMAGE_SIZE);

		$this->assertEquals(200, $response->getStatus());
		$this->assertArrayHasKey('hash', $response->getData());

		// Try retreiving generation info for the provided hash:
		$generationInfo = $this->openAiApiService->getGenerationInfo($response->getData()['hash']);
		$this->assertArrayHasKey('prompt', $generationInfo);
		$this->assertEquals($prompt, $generationInfo['prompt']);
		$this->assertArrayHasKey('urls', $generationInfo);
		$this->assertCount(2, $generationInfo['urls']);
		$this->assertEquals($url1, $generationInfo['urls'][0]->getUrl());
		$this->assertEquals($url2, $generationInfo['urls'][1]->getUrl());

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_IMAGE);
		$this->assertEquals(2, $usage);
		// Clear quota usage
		if ($clearQuota) {
			$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
		}
	}

	public function testCreateCompletionWithLocalAi() {
		$prompt = 'This is a test prompt';
		$n = 1;
		$maxTokens = 42;
		$url_base = 'http://mycustomendpoint.net';

		$this->openAiSettingsService->setServiceUrl($url_base);
		$this->openAiSettingsService->setUseBasicAuth(false);
		$this->openAiSettingsService->setChatEndpointEnabled(false);

		$prompt = 'This is a test prompt';
		$n = 1;
		$maxTokens = 42;

		$response = '{
            "id": "cmpl-123",
            "object": "text_completion",
            "created": 1612429630,
            "model": "davinci:2020-05-03",
            "choices": [
              {
                "text": "This is a test result",
                "index": 0,
                "logprobs": null,
                "finish_reason": "length"
              }
            ],
            "usage": {
              "prompt_tokens": 42,
              "completion_tokens": 42,
              "total_tokens": 42
            }
          }';

		$url = $url_base . '/v1/completions';
		$options = [
			'nextcloud' => ['allow_local_address' => true],
			'timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT,
			'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json'],
		];
		$options['body'] = json_encode(['model' => 'test_model', 'prompt' => $prompt, 'max_tokens' => $maxTokens, 'n' => $n, 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$response = $this->openAiApiController->createCompletion($prompt, $n, "test_model", $maxTokens);

		$this->assertEquals(200, $response->getStatus());
		$this->assertEquals('This is a test result', $response->getData()[0]);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(42, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testCreateChatCompletionWithLocalAi() {
		$prompt = 'This is a test prompt';
		$n = 1;
		$maxTokens = 42;
		$url_base = 'http://mycustomendpoint.net';

		$this->openAiSettingsService->setServiceUrl($url_base);
		$this->openAiSettingsService->setUseBasicAuth(false);
		$this->openAiSettingsService->setChatEndpointEnabled(true);

		$prompt = 'This is a test prompt';
		$n = 1;
		$maxTokens = 42;

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
				  "content": "Hello there, how may I assist you today?"
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

		$url = $url_base . '/v1/chat/completions';
		$options = [
			'nextcloud' => ['allow_local_address' => true],
			'timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT,
			'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json'],
		];
		$options['body'] = json_encode(['model' => 'test_model', 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => $maxTokens, 'n' => $n, 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$response = $this->openAiApiController->createCompletion($prompt, $n, "test_model", $maxTokens);

		$this->assertEquals(200, $response->getStatus());
		$this->assertEquals('Hello there, how may I assist you today?', $response->getData()[0]);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testCreateChatCompletionWithLocalAiAndBasicAuth() {
		$prompt = 'This is a test prompt';
		$n = 1;
		$maxTokens = 42;
		$url_base = 'http://mycustomendpoint.net';

		$this->openAiSettingsService->setServiceUrl($url_base);
		$this->openAiSettingsService->setUseBasicAuth(true);
		$this->openAiSettingsService->setChatEndpointEnabled(true);
		$this->openAiSettingsService->setUserBasicUser(self::TEST_USER1, 'testuser');
		$this->openAiSettingsService->setUserBasicPassword(self::TEST_USER1, 'testpassword');

		$prompt = 'This is a test prompt';
		$n = 1;
		$maxTokens = 42;

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
				  "content": "Hello there, how may I assist you today?"
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

		$url = $url_base . '/v1/chat/completions';
		$options = [
			'nextcloud' => ['allow_local_address' => true],
			'timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT,
			'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => 'Basic ' . base64_encode('testuser:testpassword'), 'Content-Type' => 'application/json'],
		];
		$options['body'] = json_encode(['model' => 'test_model', 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => $maxTokens, 'n' => $n, 'user' => self::TEST_USER1]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$response = $this->openAiApiController->createCompletion($prompt, $n, "test_model", $maxTokens);

		$this->assertEquals(200, $response->getStatus());
		$this->assertEquals('Hello there, how may I assist you today?', $response->getData()[0]);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testExceedingTextQuota() {
		$this->openAiSettingsService->setServiceUrl('');
		$this->openAiSettingsService->setUserApiKey(self::TEST_USER1, 'This is a PHPUnit test API key');

		$prompt = 'This is a test prompt';
		$n = 1;
		$maxTokens = 42;

		$quotas = $this->openAiSettingsService->getQuotas();
		foreach ($quotas as $key => $value) {
			$quotas[$key] = 1;
		}

		// Set all quotas to 1
		$this->openAiSettingsService->setQuotas($quotas);

		$this->testCreateCompletionWithOpenAi(false);

		// Before we disable the user specific api key, we should be able to exceed the quota
		$result = $this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertFalse($result);

		// Disable the private api key for the user:
		$this->openAiSettingsService->setUserApiKey(self::TEST_USER1, '');

		// Check if quota is exceeded for user:
		$result = $this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertTrue($result);

		// Now the request to the corresponding endpoint should should also fail with 429
		$response = $this->openAiApiController->createCompletion($prompt, $n, "test_model", $maxTokens);
		$this->assertEquals(429, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testExceedingTranscribeQuota() {
		$this->openAiSettingsService->setServiceUrl('');
		$this->openAiSettingsService->setUserApiKey(self::TEST_USER1, 'This is a PHPUnit test API key');

		$quotas = $this->openAiSettingsService->getQuotas();
		foreach ($quotas as $key => $value) {
			$quotas[$key] = 1;
		}

		// Set all quotas to 1
		$this->openAiSettingsService->setQuotas($quotas);

		$this->testTranscribe(false);

		// Before we disable the user specific api key, we should be able to exceed the quota
		$result = $this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_TRANSCRIPTION);
		$this->assertFalse($result);

		// Disable the private api key for the user:
		$this->openAiSettingsService->setUserApiKey(self::TEST_USER1, '');

		// Check if quota is exceeded for user:
		$result = $this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_TRANSCRIPTION);
		$this->assertTrue($result);

		// Now the request to the corresponding endpoint should should also fail with 429
		$response = $this->openAiApiController->transcribe('data:audio/mp3;base64,' . base64_encode(bin2hex(random_bytes(32))), true);
		$this->assertEquals(429, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testExceedingImageGenerationQuota() {
		$this->openAiSettingsService->setServiceUrl('');
		$this->openAiSettingsService->setUserApiKey(self::TEST_USER1, 'This is a PHPUnit test API key');

		$quotas = $this->openAiSettingsService->getQuotas();
		foreach ($quotas as $key => $value) {
			$quotas[$key] = 1;
		}

		// Set all quotas to 1
		$this->openAiSettingsService->setQuotas($quotas);

		$this->testImageGenerationWithOpenAi(false);

		// Before we disable the user specific api key, we should be able to exceed the quota

		$result = $this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_IMAGE);
		$this->assertFalse($result);

		// Disable the private api key for the user:
		$this->openAiSettingsService->setUserApiKey(self::TEST_USER1, '');

		// Check if quota is exceeded for user:
		$result = $this->openAiApiService->isQuotaExceeded(self::TEST_USER1, Application::QUOTA_TYPE_IMAGE);
		$this->assertTrue($result);

		// Now the request to the corresponding endpoint should should also fail with 429
		$response = $this->openAiApiController->createImage('This is a test prompt', 1, Application::DEFAULT_IMAGE_SIZE);
		$this->assertEquals(429, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}
}
