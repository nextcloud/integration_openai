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

namespace OCA\OpenAi\Tests\Unit\Provider;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCA\OpenAi\Service\ChunkService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\TaskProcessing\ChangeToneProvider;
use OCA\OpenAi\TaskProcessing\EmojiProvider;
use OCA\OpenAi\TaskProcessing\HeadlineProvider;
use OCA\OpenAi\TaskProcessing\ProofreadProvider;
use OCA\OpenAi\TaskProcessing\SummaryProvider;
use OCA\OpenAi\TaskProcessing\TextToSpeechProvider;
use OCA\OpenAi\TaskProcessing\TextToTextProvider;
use OCA\OpenAi\TaskProcessing\TranslateProvider;
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
class OpenAiProviderTest extends TestCase {
	public const APP_NAME = 'integration_openai';
	public const TEST_USER1 = 'testuser';
	public const OPENAI_API_BASE = 'https://api.openai.com/v1/';
	public const AUTHORIZATION_HEADER = 'Bearer This is a PHPUnit test API key';

	private OpenAiAPIService $openAiApiService;
	private OpenAiSettingsService $openAiSettingsService;
	private ChunkService $chunkService;
	/**
	 * @var MockObject|IClient
	 */
	private $iClient;
	private QuotaUsageMapper $quotaUsageMapper;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$backend = new Dummy();
		$backend->createUser(self::TEST_USER1, self::TEST_USER1);
		\OCP\Server::get(\OCP\IUserManager::class)->registerBackend($backend);
	}

	protected function setUp(): void {
		parent::setUp();

		$this->loginAsUser(self::TEST_USER1);

		$this->openAiSettingsService = \OCP\Server::get(OpenAiSettingsService::class);

		$this->chunkService = \OCP\Server::get(ChunkService::class);

		$this->quotaUsageMapper = \OCP\Server::get(QuotaUsageMapper::class);

		// We'll hijack the client service and subsequently iClient to return a mock response from the OpenAI API
		$clientService = $this->createMock(IClientService::class);
		$this->iClient = $this->createMock(IClient::class);
		$clientService->method('newClient')->willReturn($this->iClient);

		$this->openAiApiService = new OpenAiAPIService(
			\OCP\Server::get(\Psr\Log\LoggerInterface::class),
			$this->createMock(\OCP\IL10N::class),
			\OCP\Server::get(IAppConfig::class),
			\OCP\Server::get(ICacheFactory::class),
			\OCP\Server::get(QuotaUsageMapper::class),
			$this->openAiSettingsService,
			$this->createMock(\OCP\Notification\IManager::class),
			$clientService,
		);

		$this->openAiSettingsService->setUserApiKey(self::TEST_USER1, 'This is a PHPUnit test API key');
	}

	public static function tearDownAfterClass(): void {
		// Delete quota usage for test user
		$quotaUsageMapper = \OCP\Server::get(QuotaUsageMapper::class);
		try {
			$quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
		} catch (\OCP\Db\Exception|\RuntimeException|\Exception|\Throwable $e) {
			// Ignore
		}

		$backend = new \Test\Util\User\Dummy();
		$backend->deleteUser(self::TEST_USER1);
		\OCP\Server::get(\OCP\IUserManager::class)->removeBackend($backend);

		parent::tearDownAfterClass();
	}

	public function testFreePromptProvider(): void {
		$freePromptProvider = new TextToTextProvider(
			$this->openAiApiService,
			\OCP\Server::get(IAppConfig::class),
			$this->openAiSettingsService,
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
			\OCP\Server::get(LoggerInterface::class),
		);

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
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$options['body'] = json_encode([
			'model' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [['role' => 'user', 'content' => $prompt]],
			'n' => $n,
			'max_completion_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
			'user' => self::TEST_USER1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $freePromptProvider->process(self::TEST_USER1, ['input' => $prompt], fn () => null);
		$this->assertEquals('This is a test response.', $result['output']);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testEmojiProvider(): void {
		$emojiProvider = new EmojiProvider(
			$this->openAiApiService,
			\OCP\Server::get(IAppConfig::class),
			$this->openAiSettingsService,
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
		);

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

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$message = 'Give me an emoji for the following text. Output only the emoji without any other characters.' . "\n\n" . $prompt;
		$options['body'] = json_encode([
			'model' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [['role' => 'user', 'content' => $message]],
			'n' => $n,
			'max_completion_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
			'user' => self::TEST_USER1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $emojiProvider->process(self::TEST_USER1, ['input' => $prompt], fn () => null);
		$this->assertEquals('This is a test response.', $result['output']);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}


	public function testHeadlineProvider(): void {
		$headlineProvider = new HeadlineProvider(
			$this->openAiApiService,
			\OCP\Server::get(IAppConfig::class),
			$this->openAiSettingsService,
			$this->createMock(\OCP\IL10N::class),
			self::TEST_USER1,
		);

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

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$message = 'Give me the headline of the following text in its original language. Do not output the language. Output only the headline without any quotes or additional punctuation.' . "\n\n" . $prompt;
		$options['body'] = json_encode([
			'model' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [['role' => 'user', 'content' => $message]],
			'n' => $n,
			'max_completion_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
			'user' => self::TEST_USER1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

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
			$this->openAiApiService,
			\OCP\Server::get(IAppConfig::class),
			$this->openAiSettingsService,
			$this->createMock(\OCP\IL10N::class),
			$this->chunkService,
			self::TEST_USER1,
		);

		$textInput = 'This is a test prompt';
		$toneInput = 'friendlier';
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

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$message = "Reformulate the following text in a $toneInput tone in its original language. Output only the reformulation. Here is the text:" . "\n\n" . $textInput . "\n\n" . 'Do not mention the used language in your reformulation. Here is your reformulation in the same language:';
		$options['body'] = json_encode([
			'model' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [['role' => 'user', 'content' => $message]],
			'n' => $n,
			'max_completion_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
			'user' => self::TEST_USER1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

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
			$this->openAiApiService,
			\OCP\Server::get(IAppConfig::class),
			$this->openAiSettingsService,
			$this->createMock(\OCP\IL10N::class),
			$this->chunkService,
			self::TEST_USER1,
		);

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

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$systemPrompt = 'You are a helpful assistant that summarizes text in the same language as the text. '
			. 'You should only return the summary without any additional information.';
		$options['body'] = json_encode([
			'model' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user', 'content' => $prompt],
			],
			'n' => $n,
			'max_completion_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
			'user' => self::TEST_USER1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

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
			$this->openAiApiService,
			\OCP\Server::get(IAppConfig::class),
			$this->openAiSettingsService,
			$this->createMock(\OCP\IL10N::class),
			$this->chunkService,
			self::TEST_USER1,
		);

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

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$systemPrompt = 'Proofread the following text. List all spelling and grammar mistakes and how to correct them. Output only the list.';
		$options['body'] = json_encode([
			'model' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user', 'content' => $prompt],
			],
			'n' => $n,
			'max_completion_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
			'user' => self::TEST_USER1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $proofreadProvider->process(self::TEST_USER1, ['input' => $prompt], fn () => null);
		$this->assertEquals('This is a test response.', $result['output']);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testTranslationProvider(): void {
		$translationProvider = new TranslateProvider(
			$this->openAiApiService,
			\OCP\Server::get(IAppConfig::class),
			$this->openAiSettingsService,
			$this->createMock(\OCP\IL10N::class),
			\OCP\Server::get(\OCP\L10N\IFactory::class),
			$this->createMock(\OCP\ICacheFactory::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->chunkService,
			self::TEST_USER1,
		);

		$inputText = 'This is a test prompt';
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

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$options['body'] = json_encode([
			'model' => Application::DEFAULT_COMPLETION_MODEL_ID,
			'messages' => [
				['role' => 'user', 'content' => 'Translate from ' . $fromLang . ' to English (US): ' . $inputText],
			],
			'n' => $n,
			'max_completion_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
			'user' => self::TEST_USER1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $translationProvider->process(self::TEST_USER1, ['input' => $inputText, 'origin_language' => $fromLang, 'target_language' => $toLang], fn () => null);
		$this->assertEquals(['output' => 'This is a test response.'], $result);

		// Check that token usage is logged properly
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_TEXT);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

	public function testTextToSpeechProvider(): void {
		$TTSProvider = new TextToSpeechProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(IAppConfig::class),
			self::TEST_USER1,
		);

		$inputText = 'This is a test prompt';

		$response = 'BINARYDATA';

		$url = self::OPENAI_API_BASE . 'audio/speech';

		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => self::AUTHORIZATION_HEADER, 'Content-Type' => 'application/json']];
		$options['body'] = json_encode([
			'input' => $inputText,
			'voice' => Application::DEFAULT_SPEECH_VOICE,
			'model' => Application::DEFAULT_SPEECH_MODEL_ID,
			'response_format' => 'mp3',
			'speed' => 1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $TTSProvider->process(self::TEST_USER1, ['input' => $inputText], fn () => null);
		$this->assertEquals(['speech' => 'BINARYDATA'], $result);

		// Check that token usage is logged properly (should be 21 characters)
		$usage = $this->quotaUsageMapper->getQuotaUnitsOfUser(self::TEST_USER1, Application::QUOTA_TYPE_SPEECH);
		$this->assertEquals(21, $usage);
		// Clear quota usage
		$this->quotaUsageMapper->deleteUserQuotaUsages(self::TEST_USER1);
	}

}
