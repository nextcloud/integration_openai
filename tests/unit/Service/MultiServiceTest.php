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
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiFileService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\QuotaRuleService;
use OCA\OpenAi\Service\ServiceConfig;
use OCA\OpenAi\Service\ServicesService;
use OCA\OpenAi\Service\StreamingService;
use OCA\OpenAi\Service\WatermarkingService;
use OCA\OpenAi\TaskProcessing\AudioToTextProvider;
use OCA\OpenAi\TaskProcessing\TextToImageProvider;
use OCA\OpenAi\TaskProcessing\TextToSpeechProvider;
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

	private OpenAiAPIService $openAiApiService;
	private ServicesService $servicesService;
	/**
	 * @var MockObject|IClient
	 */
	private $iClient;
	/** @var ServiceConfig[] */
	private array $services = [];

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
		foreach ($this->services as $service) {
			$this->servicesService->deleteService($service->getId());
		}
		$this->services = [];
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
		$this->services[] = $service;
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
}
