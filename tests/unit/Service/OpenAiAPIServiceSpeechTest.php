<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Tests\Unit\Service;

use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServiceConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OpenAiAPIServiceSpeechTest extends TestCase {
	private LoggerInterface&MockObject $logger;

	/**
	 * @param array $response what request() returns
	 * @param array|null $captured filled with the payload passed to request()
	 */
	private function getService(array $response, ?array &$captured): OpenAiAPIService {
		$service = $this->getMockBuilder(OpenAiAPIService::class)
			->disableOriginalConstructor()
			->onlyMethods(['request', 'isQuotaExceeded', 'createQuotaUsage'])
			->getMock();
		$service->method('isQuotaExceeded')->willReturn(false);
		$this->logger = $this->createMock(LoggerInterface::class);
		foreach (['logger' => $this->logger, 'l10n' => $this->createMock(IL10N::class)] as $name => $value) {
			(new \ReflectionProperty(OpenAiAPIService::class, $name))->setValue($service, $value);
		}
		$service->method('request')->willReturnCallback(
			function ($userId, $config, $endpoint, $params) use ($response, &$captured) {
				$captured = $params;
				return $response;
			}
		);
		return $service;
	}

	public function testDefaultSpeedIsNotSentToNonOpenAiService(): void {
		$captured = null;
		$service = $this->getService(['body' => 'audio', 'content-type' => 'audio/mpeg'], $captured);
		$config = new ServiceConfig('s1', url: 'https://api.mistral.ai/v1');
		$service->requestSpeechCreation('user', $config, 'hello', 'model', 'voice', 1);
		$this->assertArrayNotHasKey('speed', $captured);
	}

	public function testCustomSpeedIsSentToNonOpenAiService(): void {
		$captured = null;
		$service = $this->getService(['body' => 'audio', 'content-type' => 'audio/mpeg'], $captured);
		$config = new ServiceConfig('s1', url: 'https://localai.example/v1');
		$service->requestSpeechCreation('user', $config, 'hello', 'model', 'voice', 1.5);
		$this->assertSame(1.5, $captured['speed']);
	}

	public function testSpeedIsAlwaysSentToOpenAi(): void {
		$captured = null;
		$service = $this->getService(['body' => 'audio', 'content-type' => 'audio/mpeg'], $captured);
		$config = new ServiceConfig('s1');
		$service->requestSpeechCreation('user', $config, 'hello', 'model', 'voice', 1);
		$this->assertSame(1.0, $captured['speed']);
	}

	public function testBase64JsonAudioIsDecoded(): void {
		$captured = null;
		$service = $this->getService(['audio_data' => base64_encode('MP3BYTES')], $captured);
		$config = new ServiceConfig('s1', url: 'https://api.mistral.ai/v1');
		$response = $service->requestSpeechCreation('user', $config, 'hello', 'model', 'voice', 1);
		$this->assertSame('MP3BYTES', $response['body']);
		$this->assertSame('audio/mpeg', $response['content-type']);
	}

	public function testContentTypeIsDetectedFromTheAudioBytes(): void {
		$captured = null;
		$wav = 'RIFF' . pack('V', 36) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', 0);
		$service = $this->getService(['audio_data' => base64_encode($wav)], $captured);
		$config = new ServiceConfig('s1', url: 'https://api.mistral.ai/v1');
		$response = $service->requestSpeechCreation('user', $config, 'hello', 'model', 'voice', 1);
		$this->assertSame($wav, $response['body']);
		$this->assertSame('audio/x-wav', $response['content-type']);
	}

	public function testInvalidBase64AudioIsLoggedAndReported(): void {
		$captured = null;
		$service = $this->getService(['audio_data' => 'data:audio/mpeg;base64,SUQz'], $captured);
		$this->logger->expects($this->once())->method('warning');
		$config = new ServiceConfig('s1', url: 'https://api.mistral.ai/v1');
		$this->expectException(\Exception::class);
		$service->requestSpeechCreation('user', $config, 'hello', 'model', 'voice', 1);
	}

	public function testBinaryResponseIsUntouched(): void {
		$captured = null;
		$binary = ['body' => "ID3\x04\x00raw", 'content-type' => 'audio/mpeg'];
		$service = $this->getService($binary, $captured);
		$config = new ServiceConfig('s1');
		$response = $service->requestSpeechCreation('user', $config, 'hello', 'model', 'voice', 1);
		$this->assertSame($binary, $response);
	}
}
