<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 *
 * This unit test checks that the providers of a service talk to that service:
 * with its URL, its credentials and its request timeout. It does not test the
 * actual openAI/LocalAI api calls, but rather mocks them.
 */

namespace OCA\OpenAi\Tests\Unit\Service;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCA\OpenAi\Service\ChunkService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiFileService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\QuotaRuleService;
use OCA\OpenAi\Service\ServiceConfig;
use OCA\OpenAi\Service\ServicesService;
use OCA\OpenAi\Service\StreamingService;
use OCA\OpenAi\Service\TranslateService;
use OCA\OpenAi\Service\WatermarkingService;
use OCA\OpenAi\TaskProcessing\AudioToTextProvider;
use OCA\OpenAi\TaskProcessing\ImageToImageProvider;
use OCA\OpenAi\TaskProcessing\ProviderFactory;
use OCA\OpenAi\TaskProcessing\TextToImageProvider;
use OCA\OpenAi\TaskProcessing\TextToSpeechProvider;
use OCA\OpenAi\TaskProcessing\TextToTextChatProvider;
use OCA\OpenAi\TaskProcessing\TextToTextProvider;
use OCA\OpenAi\TaskProcessing\TranslateProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;
use Test\Util\User\Dummy;

/**
 * @group DB
 */
class MultiServiceTest extends TestCase {
	public const APP_NAME = 'integration_openai';
	public const TEST_USER1 = 'testuser';
	public const SPEECH_BASE = 'https://speech-generator.ai/v1';
	public const APIKEY_SPEECH = 'This is a speech PHPUnit test API key';
	public const REQUEST_TIMEOUT_SPEECH = 10;
	public const SPEECH_MODEL = 'my-tts-model';
	public const IMAGE_BASE = 'https://image-generator.ai/v1';
	public const APIKEY_IMAGE = 'This is a image PHPUnit test API key';
	public const REQUEST_TIMEOUT_IMAGE = 12;
	public const IMAGE_MODEL = 'my-image-model';
	public const TRANSCRIPTION_BASE = 'https://transcription-generator.ai/v1';
	public const APIKEY_TRANSCRIPTION = 'This is a transcription PHPUnit test API key';
	public const REQUEST_TIMEOUT_TRANSCRIPTION = 14;
	public const TRANSCRIPTION_MODEL = 'my-whisper-model';
	public const TEXT_BASE = 'https://text-generator.ai/v1';
	public const APIKEY_TEXT = 'This is a text PHPUnit test API key';
	public const TEXT_MODEL = 'my/text-model';

	private OpenAiAPIService $openAiApiService;
	private ServicesService $servicesService;
	/**
	 * @var MockObject|IClient
	 */
	private $iClient;
	/**
	 * The services created by a test, removed again in tearDown().
	 *
	 * Not named $services: Test\TestCase uses that for its overridden server
	 * services.
	 *
	 * @var ServiceConfig[]
	 */
	private array $createdServices = [];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$backend = new Dummy();
		$backend->createUser(self::TEST_USER1, self::TEST_USER1);
		\OCP\Server::get(\OCP\IUserManager::class)->registerBackend($backend);
	}

	protected function setUp(): void {
		parent::setUp();

		$this->loginAsUser(self::TEST_USER1);

		$this->servicesService = \OCP\Server::get(ServicesService::class);

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
			\OCP\Server::get(OpenAiSettingsService::class),
			new StreamingService(
				$this->createMock(\OCP\IL10N::class),
			),
			new OpenAiFileService(
				$this->createMock(\OCP\IL10N::class),
				$this->createMock(\OCP\Files\IRootFolder::class),
				$this->createMock(\OCP\TaskProcessing\IManager::class),
				$this->createMock(\Psr\Log\LoggerInterface::class),
			),
			$this->createMock(\OCP\Notification\IManager::class),
			\OCP\Server::get(QuotaRuleService::class),
			$this->servicesService,
			$clientService,
			true
		);
	}

	protected function tearDown(): void {
		foreach ($this->createdServices as $service) {
			$this->servicesService->deleteService($service->getId());
		}
		$this->createdServices = [];
		parent::tearDown();
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

	/**
	 * @param array<string, mixed> $values
	 */
	private function addService(array $values): ServiceConfig {
		$service = $this->servicesService->addService($values);
		$this->createdServices[] = $service;
		return $service;
	}

	public function testTextToSpeechProvider(): void {
		$service = $this->addService([
			'url' => self::SPEECH_BASE,
			'api_key' => self::APIKEY_SPEECH,
			'request_timeout' => self::REQUEST_TIMEOUT_SPEECH,
			'tts_models' => [self::SPEECH_MODEL],
		]);

		$ttsProvider = new TextToSpeechProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(WatermarkingService::class),
			$service,
			self::SPEECH_MODEL,
		);

		// the provider is named after its model and the service it belongs to
		$this->assertSame(self::SPEECH_MODEL . ' (speech-generator.ai)', $ttsProvider->getName());
		$this->assertStringContainsString($service->getId(), $ttsProvider->getId());

		$inputText = 'This is a test prompt';

		$response = file_get_contents(__DIR__ . '/../../res/speech.mp3');

		if (!$response) {
			throw new \RuntimeException('Could not read test resourcce `speech.mp3`');
		}

		$url = self::SPEECH_BASE . '/audio/speech';

		$options = ['timeout' => self::REQUEST_TIMEOUT_SPEECH, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => 'Bearer ' . self::APIKEY_SPEECH, 'Content-Type' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]];
		$options['body'] = json_encode([
			'input' => $inputText,
			'voice' => Application::DEFAULT_SPEECH_VOICE,
			'model' => self::SPEECH_MODEL,
			'response_format' => 'mp3',
			'speed' => 1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$ttsProvider->process(self::TEST_USER1, ['input' => $inputText], fn () => null, includeWatermark: false);
	}

	public function testTextToImageProvider(): void {
		$service = $this->addService([
			'url' => self::IMAGE_BASE,
			'api_key' => self::APIKEY_IMAGE,
			'request_timeout' => self::REQUEST_TIMEOUT_IMAGE,
			'image_models' => [self::IMAGE_MODEL],
		]);

		$textToImageProvider = new TextToImageProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(IClientService::class),
			\OCP\Server::get(WatermarkingService::class),
			$service,
			self::IMAGE_MODEL,
		);

		$inputText = 'This is a test prompt';

		$responseImage = file_get_contents(__DIR__ . '/../../res/trees.jpg');

		if (!$responseImage) {
			throw new \RuntimeException('Could not read test resourcce `trees.jpg`');
		}

		$response = json_encode([
			'data' => [
				[
					'b64_json' => base64_encode($responseImage),
				]
			]
		]);

		$url = self::IMAGE_BASE . '/images/generations';

		$options = ['timeout' => self::REQUEST_TIMEOUT_IMAGE, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => 'Bearer ' . self::APIKEY_IMAGE, 'Content-Type' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]];
		$options['body'] = json_encode([
			'prompt' => $inputText,
			'size' => '1024x1024',
			'n' => 1,
			'model' => self::IMAGE_MODEL,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$textToImageProvider->process(self::TEST_USER1, ['input' => $inputText, 'numberOfImages' => 1], fn () => null);
	}

	public function testImageToImageProvider(): void {
		$service = $this->addService([
			'url' => self::IMAGE_BASE,
			'api_key' => self::APIKEY_IMAGE,
			'request_timeout' => self::REQUEST_TIMEOUT_IMAGE,
			'image_models' => [self::IMAGE_MODEL],
		]);

		$imageToImageProvider = new ImageToImageProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(IClientService::class),
			\OCP\Server::get(WatermarkingService::class),
			$service,
			self::IMAGE_MODEL,
		);

		$inputImage = file_get_contents(__DIR__ . '/../../res/trees.jpg');
		if (!$inputImage) {
			throw new \RuntimeException('Could not read test resource `trees.jpg`');
		}

		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getContent')->willReturn($inputImage);
		$file->method('getSize')->willReturn(strlen($inputImage));
		$file->method('getMimeType')->willReturn('image/jpeg');

		$prompt = 'Make the sky blue';
		$response = json_encode([
			'data' => [
				[
					'b64_json' => base64_encode($inputImage),
				]
			]
		]);

		$wellKnownUrl = substr(self::IMAGE_BASE, 0, -2) . '.well-known/localai.json';
		$wellKnownResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$wellKnownResponse->method('getBody')->willReturn('{"version":"1.0"}');
		$wellKnownResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('get')->with(
			$wellKnownUrl,
			['http_errors' => false, 'nextcloud' => ['allow_local_address' => true]],
		)->willReturn($wellKnownResponse);

		$url = self::IMAGE_BASE . '/images/generations';
		$options = [
			'timeout' => self::REQUEST_TIMEOUT_IMAGE,
			'headers' => [
				'User-Agent' => Application::USER_AGENT,
				'Authorization' => 'Bearer ' . self::APIKEY_IMAGE,
				'Content-Type' => 'application/json',
			],
			'nextcloud' => ['allow_local_address' => true],
			'body' => json_encode([
				'prompt' => $prompt,
				'size' => '1024x1024',
				'n' => 1,
				'ref_images' => [base64_encode($inputImage)],
				'model' => self::IMAGE_MODEL,
			]),
		];

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$imageToImageProvider->process(
			self::TEST_USER1,
			['input' => [$file], 'prompt' => $prompt],
			fn () => null,
		);
	}

	public function testImageToImageProviderOpenRouter(): void {
		$openRouterBase = 'https://openrouter.ai/api/v1/';
		$service = $this->addService([
			'url' => $openRouterBase,
			'api_key' => self::APIKEY_IMAGE,
			'request_timeout' => self::REQUEST_TIMEOUT_IMAGE,
			'image_models' => [self::IMAGE_MODEL],
		]);

		$imageToImageProvider = new ImageToImageProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(IClientService::class),
			\OCP\Server::get(WatermarkingService::class),
			$service,
			self::IMAGE_MODEL,
		);

		$inputImage = file_get_contents(__DIR__ . '/../../res/trees.jpg');
		if (!$inputImage) {
			throw new \RuntimeException('Could not read test resource `trees.jpg`');
		}

		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getContent')->willReturn($inputImage);
		$file->method('getSize')->willReturn(strlen($inputImage));
		$file->method('getMimeType')->willReturn('image/jpeg');

		$prompt = 'Make the sky blue';
		$response = json_encode([
			'data' => [
				[
					'b64_json' => base64_encode($inputImage),
				]
			]
		]);

		$url = $openRouterBase . 'images';
		$options = [
			'timeout' => self::REQUEST_TIMEOUT_IMAGE,
			'headers' => [
				'User-Agent' => Application::USER_AGENT,
				'Authorization' => 'Bearer ' . self::APIKEY_IMAGE,
				'Content-Type' => 'application/json',
			],
			'nextcloud' => ['allow_local_address' => true],
			'body' => json_encode([
				'prompt' => $prompt,
				'size' => '1024x1024',
				'n' => 1,
				'input_references' => [
					[
						'type' => 'image_url',
						'image_url' => [
							'url' => 'data:image/jpeg;base64,' . base64_encode($inputImage),
						],
					],
				],
				'model' => self::IMAGE_MODEL,
			]),
		];

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$imageToImageProvider->process(
			self::TEST_USER1,
			['input' => [$file], 'prompt' => $prompt],
			fn () => null,
		);
	}

	public function testAudioToTextProvider(): void {
		$service = $this->addService([
			'url' => self::TRANSCRIPTION_BASE,
			'api_key' => self::APIKEY_TRANSCRIPTION,
			'request_timeout' => self::REQUEST_TIMEOUT_TRANSCRIPTION,
			'stt_models' => [self::TRANSCRIPTION_MODEL],
		]);

		$audioToTextProvider = new AudioToTextProvider(
			$this->openAiApiService,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(\OCP\IL10N::class),
			$service,
			self::TRANSCRIPTION_MODEL,
		);

		$file = $this->createMock(\OCP\Files\File::class);

		$inputSpeech = file_get_contents(__DIR__ . '/../../res/speech.mp3');

		if (!$inputSpeech) {
			throw new \RuntimeException('Could not read test resource `speech.mp3`');
		}
		$file->method('isReadable')->willReturn(true);
		$file->method('getContent')->willReturn($inputSpeech);

		$response = json_encode([
			'text' => 'Transcribed text'
		]);

		$url = self::TRANSCRIPTION_BASE . '/audio/transcriptions';

		$options = ['timeout' => self::REQUEST_TIMEOUT_TRANSCRIPTION, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => 'Bearer ' . self::APIKEY_TRANSCRIPTION], 'nextcloud' => ['allow_local_address' => true]];
		$options['multipart'] = [
			['name' => 'model', 'contents' => self::TRANSCRIPTION_MODEL],
			['name' => 'file', 'contents' => $inputSpeech, 'filename' => 'file.mp3'],
			['name' => 'response_format', 'contents' => 'verbose_json'],
		];
		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$audioToTextProvider->process(self::TEST_USER1, ['input' => $file], fn () => null);
	}

	public function testExtraHeadersAreStoredNormalized(): void {
		$service = $this->addService([
			'url' => self::SPEECH_BASE,
			'extra_headers' => [
				['name' => ' X-Tenant ', 'value' => ' acme '],
			],
		]);

		$this->assertSame(
			[['name' => 'X-Tenant', 'value' => 'acme']],
			$service->getExtraHeaders(),
		);
	}

	/**
	 * @dataProvider invalidExtraHeadersProvider
	 */
	public function testInvalidExtraHeadersAreRejected(array $extraHeaders): void {
		$this->expectException(\Exception::class);
		$this->servicesService->addService([
			'url' => self::SPEECH_BASE,
			'extra_headers' => $extraHeaders,
		]);
	}

	public function invalidExtraHeadersProvider(): array {
		return [
			'a name that is not an HTTP token' => [[['name' => 'X Api Key', 'value' => 'secret']]],
			'an empty name' => [[['name' => '', 'value' => 'secret']]],
			'a name that is only spaces' => [[['name' => ' ', 'value' => 'secret']]],
			'a name with an injected line break' => [[['name' => "X-Tenant\r\nX-Evil", 'value' => 'a']]],
			'a value with an injected line break' => [[['name' => 'X-Tenant', 'value' => "a\r\nb"]]],
			'a row without a value' => [[['name' => 'X-Tenant']]],
			'a row that is not a pair' => [[['nope']]],
			'a value with an unknown variable' => [[['name' => 'X-Session', 'value' => '{$conversationid}']]],
			'a value with a mistyped variable' => [[['name' => 'X-Session', 'value' => '{$Conversation_ID}']]],
			'a value with an empty variable' => [[['name' => 'X-Session', 'value' => '{$}']]],
		];
	}

	public function testExtraHeadersAreSentAndCannotOverrideTheApiKey(): void {
		$service = $this->addService([
			'url' => self::SPEECH_BASE,
			'api_key' => self::APIKEY_SPEECH,
			'request_timeout' => self::REQUEST_TIMEOUT_SPEECH,
			'tts_models' => [self::SPEECH_MODEL],
			'extra_headers' => [
				['name' => 'X-Tenant', 'value' => 'acme'],
				['name' => 'Authorization', 'value' => 'Bearer injected'],
			],
		]);

		$ttsProvider = new TextToSpeechProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(WatermarkingService::class),
			$service,
			self::SPEECH_MODEL,
		);

		$inputText = 'This is a test prompt';

		$response = file_get_contents(__DIR__ . '/../../res/speech.mp3');

		if (!$response) {
			throw new \RuntimeException('Could not read test resourcce `speech.mp3`');
		}

		$url = self::SPEECH_BASE . '/audio/speech';

		$options = ['timeout' => self::REQUEST_TIMEOUT_SPEECH, 'headers' => ['User-Agent' => Application::USER_AGENT, 'X-Tenant' => 'acme', 'Authorization' => 'Bearer ' . self::APIKEY_SPEECH, 'Content-Type' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]];
		$options['body'] = json_encode([
			'input' => $inputText,
			'voice' => Application::DEFAULT_SPEECH_VOICE,
			'model' => self::SPEECH_MODEL,
			'response_format' => 'mp3',
			'speed' => 1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$ttsProvider->process(self::TEST_USER1, ['input' => $inputText], fn () => null, includeWatermark: false);
	}

	public function testExtraHeadersInImageRequestOptions(): void {
		$service = $this->addService([
			'url' => self::IMAGE_BASE,
			'api_key' => self::APIKEY_IMAGE,
			'image_request_auth' => false,
			'extra_headers' => [['name' => 'X-Tenant', 'value' => 'acme']],
		]);

		$options = $this->openAiApiService->getImageRequestOptions(self::TEST_USER1, $service);

		$this->assertSame([
			'timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT,
			'headers' => ['User-Agent' => Application::USER_AGENT, 'X-Tenant' => 'acme'],
		], $options);
	}

	public function testConversationIdHeaderIsExpandedOnChatRequests(): void {
		$service = $this->addService([
			'url' => self::TEXT_BASE,
			'api_key' => self::APIKEY_TEXT,
			'text_models' => [self::TEXT_MODEL],
			'extra_headers' => [
				['name' => 'X-Tenant', 'value' => 'acme'],
				['name' => 'X-Session', 'value' => '{$conversation_id}'],
			],
		]);

		$chatProvider = new TextToTextChatProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$service,
			self::TEXT_MODEL,
		);

		$systemPrompt = 'You are a helpful assistant';
		$userPrompt = 'Hello';

		$response = json_encode([
			'choices' => [
				['message' => ['role' => 'assistant', 'content' => 'Chat answer']],
			],
		]);

		$url = self::TEXT_BASE . '/chat/completions';
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'X-Tenant' => 'acme', 'X-Session' => '4242', 'Authorization' => 'Bearer ' . self::APIKEY_TEXT, 'Content-Type' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]];
		$options['body'] = json_encode([
			'model' => self::TEXT_MODEL,
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user', 'content' => $userPrompt],
			],
			'n' => 1,
			'stream' => false,
			'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $chatProvider->process(self::TEST_USER1, [
			'input' => $userPrompt,
			'system_prompt' => $systemPrompt,
			'history' => [],
			'conversation_id' => '4242',
		], fn () => null);

		$this->assertSame('Chat answer', $result['output']);
	}

	public function testConversationIdHeaderIsGeneratedWhenTheConversationIsUnknown(): void {
		$service = $this->addService([
			'url' => self::TEXT_BASE,
			'api_key' => self::APIKEY_TEXT,
			'text_models' => [self::TEXT_MODEL],
			'extra_headers' => [
				['name' => 'X-Tenant', 'value' => 'acme'],
				['name' => 'X-Session', 'value' => 'conv-{$conversation_id}'],
			],
		]);

		$chatProvider = new TextToTextChatProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$service,
			self::TEXT_MODEL,
		);

		$systemPrompt = 'You are a helpful assistant';
		$userPrompt = 'Hello';

		$response = json_encode([
			'choices' => [
				['message' => ['role' => 'assistant', 'content' => 'Chat answer']],
			],
		]);

		// the same request without the conversation_id input: the header is
		// still sent, with a throwaway ID generated for the request
		$url = self::TEXT_BASE . '/chat/completions';
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'X-Tenant' => 'acme', 'Authorization' => 'Bearer ' . self::APIKEY_TEXT, 'Content-Type' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]];
		$options['body'] = json_encode([
			'model' => self::TEXT_MODEL,
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user', 'content' => $userPrompt],
			],
			'n' => 1,
			'stream' => false,
			'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$generatedIds = [];
		$this->iClient->expects($this->exactly(2))->method('post')->with(
			$url,
			$this->callback(static function (array $actualOptions) use ($options, &$generatedIds): bool {
				$generatedId = $actualOptions['headers']['X-Session'] ?? null;
				if (!is_string($generatedId) || preg_match('/^conv-[0-9a-f]{32}$/', $generatedId) !== 1) {
					return false;
				}
				$generatedIds[] = $generatedId;
				unset($actualOptions['headers']['X-Session']);
				return $actualOptions == $options;
			}),
		)->willReturn($iResponse);

		$result = $chatProvider->process(self::TEST_USER1, [
			'input' => $userPrompt,
			'system_prompt' => $systemPrompt,
			'history' => [],
		], fn () => null);

		$this->assertSame('Chat answer', $result['output']);

		$result = $chatProvider->process(self::TEST_USER1, [
			'input' => $userPrompt,
			'system_prompt' => $systemPrompt,
			'history' => [],
		], fn () => null);

		$this->assertSame('Chat answer', $result['output']);

		// a different throwaway ID per request: unrelated tasks must not share
		// a stateful conversation on the service
		$this->assertCount(2, $generatedIds);
		$this->assertNotSame($generatedIds[0], $generatedIds[1]);
	}

	public function testConversationIdHeaderIsDroppedOnNonChatRequests(): void {
		$service = $this->addService([
			'url' => self::SPEECH_BASE,
			'api_key' => self::APIKEY_SPEECH,
			'request_timeout' => self::REQUEST_TIMEOUT_SPEECH,
			'tts_models' => [self::SPEECH_MODEL],
			'extra_headers' => [
				['name' => 'X-Tenant', 'value' => 'acme'],
				['name' => 'X-Session', 'value' => '{$conversation_id}'],
			],
		]);

		$ttsProvider = new TextToSpeechProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(WatermarkingService::class),
			$service,
			self::SPEECH_MODEL,
		);

		$inputText = 'This is a test prompt';

		$response = file_get_contents(__DIR__ . '/../../res/speech.mp3');

		if (!$response) {
			throw new \RuntimeException('Could not read test resourcce `speech.mp3`');
		}

		// speech is not a chat, so the header referencing the conversation is
		// not sent, while the static one is
		$url = self::SPEECH_BASE . '/audio/speech';
		$options = ['timeout' => self::REQUEST_TIMEOUT_SPEECH, 'headers' => ['User-Agent' => Application::USER_AGENT, 'X-Tenant' => 'acme', 'Authorization' => 'Bearer ' . self::APIKEY_SPEECH, 'Content-Type' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]];
		$options['body'] = json_encode([
			'input' => $inputText,
			'voice' => Application::DEFAULT_SPEECH_VOICE,
			'model' => self::SPEECH_MODEL,
			'response_format' => 'mp3',
			'speed' => 1,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$ttsProvider->process(self::TEST_USER1, ['input' => $inputText], fn () => null, includeWatermark: false);
	}

	/**
	 * Non-chat providers that go through the chat completion endpoint must
	 * expand the conversation ID too, otherwise services requiring the header
	 * reject the request.
	 */
	public function testConversationIdHeaderIsExpandedOnFreePromptRequests(): void {
		$service = $this->addService([
			'url' => self::TEXT_BASE,
			'api_key' => self::APIKEY_TEXT,
			'text_models' => [self::TEXT_MODEL],
			'extra_headers' => [
				['name' => 'X-Tenant', 'value' => 'acme'],
				['name' => 'X-Session', 'value' => '{$conversation_id}'],
			],
		]);

		$freePromptProvider = new TextToTextProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$service,
			self::TEXT_MODEL,
		);

		$userPrompt = 'Hello';

		$response = json_encode([
			'choices' => [
				['message' => ['role' => 'assistant', 'content' => 'Free prompt answer']],
			],
		]);

		$url = self::TEXT_BASE . '/chat/completions';
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'X-Tenant' => 'acme', 'X-Session' => '4242', 'Authorization' => 'Bearer ' . self::APIKEY_TEXT, 'Content-Type' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]];
		$options['body'] = json_encode([
			'model' => self::TEXT_MODEL,
			'messages' => [
				['role' => 'user', 'content' => $userPrompt],
			],
			'n' => 1,
			'stream' => false,
			'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $freePromptProvider->process(self::TEST_USER1, [
			'input' => $userPrompt,
			'conversation_id' => '4242',
		], fn () => null);

		$this->assertSame('Free prompt answer', $result['output']);
	}

	public function testConversationIdHeaderIsExpandedOnTranslationRequests(): void {
		$service = $this->addService([
			'url' => self::TEXT_BASE,
			'api_key' => self::APIKEY_TEXT,
			'text_models' => [self::TEXT_MODEL],
			'extra_headers' => [
				['name' => 'X-Tenant', 'value' => 'acme'],
				['name' => 'X-Session', 'value' => '{$conversation_id}'],
			],
		]);

		$translateProvider = new TranslateProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			new TranslateService(
				\OCP\Server::get(\Psr\Log\LoggerInterface::class),
				$this->openAiApiService,
				new ChunkService(),
				\OCP\Server::get(ICacheFactory::class),
			),
			$service,
			self::TEXT_MODEL,
		);

		$inputText = 'Hello world';
		$coreLanguages = TranslateService::getCoreLanguagesByCode();
		$toLanguage = $coreLanguages['fr'] ?? 'fr';
		$prompt = 'Translate the following text to ' . $toLanguage . ': ' . PHP_EOL . PHP_EOL . $inputText;

		$response = json_encode([
			'choices' => [
				['message' => ['role' => 'assistant', 'content' => json_encode(['translation' => 'Bonjour le monde'])]],
			],
		]);

		$url = self::TEXT_BASE . '/chat/completions';
		$options = ['timeout' => Application::OPENAI_DEFAULT_REQUEST_TIMEOUT, 'headers' => ['User-Agent' => Application::USER_AGENT, 'X-Tenant' => 'acme', 'X-Session' => '4242', 'Authorization' => 'Bearer ' . self::APIKEY_TEXT, 'Content-Type' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]];
		$options['body'] = json_encode([
			'response_format' => TranslateService::JSON_RESPONSE_FORMAT['response_format'],
			'model' => self::TEXT_MODEL,
			'messages' => [
				['role' => 'system', 'content' => TranslateService::SYSTEM_PROMPT],
				['role' => 'user', 'content' => $prompt],
			],
			'n' => 1,
			'stream' => false,
			'max_tokens' => Application::DEFAULT_MAX_NUM_OF_TOKENS,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$result = $translateProvider->process(self::TEST_USER1, [
			'input' => $inputText,
			'origin_language' => 'detect_language',
			'target_language' => 'fr',
			'conversation_id' => '4242',
		], fn () => null);

		$this->assertSame('Bonjour le monde', $result['output']);
	}

	public function testProvidersOfDifferentServicesHaveDifferentIds(): void {
		$first = $this->addService(['url' => self::IMAGE_BASE, 'image_models' => [self::IMAGE_MODEL]]);
		$second = $this->addService(['url' => self::SPEECH_BASE, 'image_models' => [self::IMAGE_MODEL]]);

		$makeProvider = fn (ServiceConfig $service) => new TextToImageProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(IClientService::class),
			\OCP\Server::get(WatermarkingService::class),
			$service,
			self::IMAGE_MODEL,
		);

		$this->assertNotSame($makeProvider($first)->getId(), $makeProvider($second)->getId());
	}

	public function testProviderIdsEndInTheirTaskTypeId(): void {
		$service = $this->addService([
			'url' => self::IMAGE_BASE,
			'text_models' => [self::TEXT_MODEL],
			'image_models' => [self::IMAGE_MODEL],
			'stt_models' => [self::TRANSCRIPTION_MODEL],
			'tts_models' => [self::SPEECH_MODEL],
			'translation_enabled' => true,
			'multimodal_image_enabled' => true,
			'multimodal_audio_enabled' => true,
		]);

		$models = implode('|', array_map(
			static fn (string $model) => preg_quote(TextToImageProvider::slugifyModel($model), '/'),
			[self::TEXT_MODEL, self::IMAGE_MODEL, self::TRANSCRIPTION_MODEL, self::SPEECH_MODEL],
		));
		$prefix = Application::APP_ID . '-' . $service->getId() . '-';

		$checked = 0;
		foreach (\OCP\Server::get(ProviderFactory::class)->getProviders() as $provider) {
			if (!str_starts_with($provider->getId(), $prefix)) {
				// a service another test left behind
				continue;
			}
			// the ID says which service, which model and which task type, and
			// nothing else but the variant of a provider that serves a task
			// type its service already covers
			$taskSlug = TextToImageProvider::slugifyTaskType($provider->getTaskTypeId());
			$this->assertMatchesRegularExpression(
				'/^' . preg_quote($prefix, '/') . '(' . $models . ')-' . preg_quote($taskSlug, '/') . '(-[a-z-]+)?$/',
				$provider->getId(),
			);
			$checked++;
		}
		$this->assertGreaterThan(15, $checked, 'the factory should have built a provider per task type');
	}

	public function testEdenAiServiceIsRecognisedOnBothEndpoints(): void {
		$global = $this->addService(['url' => 'https://api.edenai.run/v3']);
		$this->assertTrue($global->isUsingEdenAi());
		$this->assertFalse($global->isUsingOpenAi());

		// the European endpoint only exposes models cleared for EU processing
		$european = $this->addService(['url' => 'https://api.eu.edenai.run/v3']);
		$this->assertTrue($european->isUsingEdenAi());

		$mixedCase = $this->addService(['url' => 'HTTPS://API.EDENAI.RUN/v3']);
		$this->assertTrue($mixedCase->isUsingEdenAi());

		$other = $this->addService(['url' => self::IMAGE_BASE]);
		$this->assertFalse($other->isUsingEdenAi());
	}
}
