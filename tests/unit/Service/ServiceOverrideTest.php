<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 *
 * This unit test is designed to test the functionality of all providers
 * exposed by the app. It does not test the
 * actual openAI/LocalAI api calls, but rather mocks them.
 */

namespace OCA\OpenAi\Tests\Unit\Service;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCA\OpenAi\Service\ChunkService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\QuotaRuleService;
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
class ServiceOverrideTest extends TestCase {
	public const APP_NAME = 'integration_openai';
	public const TEST_USER1 = 'testuser';
	public const OPENAI_API_BASE = 'https://api.openai.com/v1/';
	public const OVERRIDE_SPEECH_BASE = 'https://speech-generator.ai/v1/';
	public const APIKEY_SPEECH = 'This is a speech PHPUnit test API key';
	public const REQUEST_TIMEOUT_SPEECH = 10;
	public const OVERRIDE_IMAGE_BASE = 'https://image-generator.ai/v1/';
	public const APIKEY_IMAGE = 'This is a image PHPUnit test API key';
	public const REQUEST_TIMEOUT_IMAGE = 12;
	public const OVERRIDE_TRANSCRIPTION_BASE = 'https://transcription-generator.ai/v1/';
	public const APIKEY_TRANSCRIPTION = 'This is a transcription PHPUnit test API key';
	public const REQUEST_TIMEOUT_TRANSCRIPTION = 14;

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
			\OCP\Server::get(QuotaRuleService::class),
			$clientService,
		);
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

		$openAiSettingsService = \OCP\Server::get(OpenAiSettingsService::class);
		$openAiSettingsService->setImageServiceUrl('');
		$openAiSettingsService->setTtsServiceUrl('');
		$openAiSettingsService->setSttServiceUrl('');

		parent::tearDownAfterClass();
	}

	public function testTextToSpeechProvider(): void {
		$this->openAiSettingsService->setTtsServiceUrl(self::OVERRIDE_SPEECH_BASE);
		$this->openAiSettingsService->setAdminTtsApiKey(self::APIKEY_SPEECH);
		$this->openAiSettingsService->setTtsRequestTimeout(self::REQUEST_TIMEOUT_SPEECH);

		$TTSProvider = new TextToSpeechProvider(
			$this->openAiApiService,
			$l10n = $this->createMock(\OCP\IL10N::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(IAppConfig::class),
			self::TEST_USER1,
			\OCP\Server::get(WatermarkingService::class),
		);

		$inputText = 'This is a test prompt';

		$response = file_get_contents(__DIR__ . '/../../res/speech.mp3');

		if (!$response) {
			throw new \RuntimeException('Could not read test resourcce `speech.mp3`');
		}

		$url = self::OVERRIDE_SPEECH_BASE . 'audio/speech';

		$options = ['timeout' => self::REQUEST_TIMEOUT_SPEECH, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => 'Bearer ' . self::APIKEY_SPEECH, 'Content-Type' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]];
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

		$TTSProvider->process(self::TEST_USER1, ['input' => $inputText], fn () => null, includeWatermark: false);
	}

	public function testTextToImageProvider(): void {
		$this->openAiSettingsService->setImageServiceUrl(self::OVERRIDE_IMAGE_BASE);
		$this->openAiSettingsService->setAdminImageApiKey(self::APIKEY_IMAGE);
		$this->openAiSettingsService->setImageRequestTimeout(self::REQUEST_TIMEOUT_IMAGE);

		$TextToImageProvider = new TextToImageProvider(
			$this->openAiApiService,
			$this->createMock(\OCP\IL10N::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(IClientService::class),
			\OCP\Server::get(IAppConfig::class),
			self::TEST_USER1,
			\OCP\Server::get(WatermarkingService::class),
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

		$url = self::OVERRIDE_IMAGE_BASE . 'images/generations';

		$options = ['timeout' => self::REQUEST_TIMEOUT_IMAGE, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => 'Bearer ' . self::APIKEY_IMAGE, 'Content-Type' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]];
		$options['body'] = json_encode([
			'prompt' => $inputText,
			'size' => '1024x1024',
			'n' => 1,
			'model' => Application::DEFAULT_IMAGE_MODEL_ID,
		]);

		$iResponse = $this->createMock(\OCP\Http\Client\IResponse::class);
		$iResponse->method('getHeader')->with('Content-Type')->willReturn('application/json');
		$iResponse->method('getBody')->willReturn($response);
		$iResponse->method('getStatusCode')->willReturn(200);

		$this->iClient->expects($this->once())->method('post')->with($url, $options)->willReturn($iResponse);

		$TextToImageProvider->process(self::TEST_USER1, ['input' => $inputText, 'numberOfImages' => 1], fn () => null);
	}

	public function testAudioToTextProvider(): void {
		$this->openAiSettingsService->setSttServiceUrl(self::OVERRIDE_TRANSCRIPTION_BASE);
		$this->openAiSettingsService->setAdminSttApiKey(self::APIKEY_TRANSCRIPTION);
		$this->openAiSettingsService->setSttRequestTimeout(self::REQUEST_TIMEOUT_TRANSCRIPTION);

		$audioToTextProvider = new AudioToTextProvider(
			$this->openAiApiService,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			\OCP\Server::get(IAppConfig::class),
			$this->createMock(\OCP\IL10N::class),
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

		$url = self::OVERRIDE_TRANSCRIPTION_BASE . 'audio/transcriptions';

		$options = ['timeout' => self::REQUEST_TIMEOUT_TRANSCRIPTION, 'headers' => ['User-Agent' => Application::USER_AGENT, 'Authorization' => 'Bearer ' . self::APIKEY_TRANSCRIPTION], 'nextcloud' => ['allow_local_address' => true]];
		$options['multipart'] = [
			['name' => 'model', 'contents' => Application::DEFAULT_TRANSCRIPTION_MODEL_ID],
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

}
