<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Tests\Unit\Provider;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServiceConfig;
use OCA\OpenAi\Service\WatermarkingService;
use OCA\OpenAi\TaskProcessing\ImageToImageProvider;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImageToImageProviderSizeTest extends TestCase {
	/** @var OpenAiAPIService&MockObject */
	private OpenAiAPIService $openAiAPIService;
	/** @var IL10N&MockObject */
	private IL10N $l10n;

	protected function setUp(): void {
		parent::setUp();

		$this->openAiAPIService = $this->createMock(OpenAiAPIService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf(str_replace('%%', '%', preg_replace('/%\d+\$/', '%', $text) ?? $text), $parameters)
		);
	}

	/**
	 * @dataProvider gptImage1InvalidSizeProvider
	 */
	public function testGptImage1RejectsInvalidSize(string $size): void {
		$provider = $this->createProvider(
			ServiceConfig::fromArray('openai', []),
			Application::DEFAULT_IMAGE_MODEL_ID,
		);

		$this->openAiAPIService->expects($this->never())->method('requestImageEdit');
		$this->expectException(UserFacingProcessingException::class);

		$provider->process('user', $this->validInput(['size' => $size]), static fn () => null);
	}

	/**
	 * @return list<list{string}>
	 */
	public static function gptImage1InvalidSizeProvider(): array {
		return [
			['512x512'],
			['2048x2048'],
			['1024x1792'],
			['1792x1024'],
			['256x256'],
		];
	}

	/**
	 * @dataProvider gptImage1ValidSizeProvider
	 */
	public function testGptImage1AcceptsValidSize(string $size): void {
		$provider = $this->createProvider(
			ServiceConfig::fromArray('openai', []),
			'gpt-image-1',
		);

		$this->openAiAPIService->expects($this->once())
			->method('requestImageEdit')
			->with(
				'user',
				$this->isInstanceOf(ServiceConfig::class),
				'edit me',
				$this->isType('array'),
				'gpt-image-1',
				$size,
			)
			->willReturn(['data' => [['b64_json' => base64_encode('img')]]]);

		$result = $provider->process('user', $this->validInput(['size' => $size]), static fn () => null);
		$this->assertSame(['output' => 'img'], $result);
	}

	/**
	 * @return list<list{string}>
	 */
	public static function gptImage1ValidSizeProvider(): array {
		return [
			['1024x1024'],
			['1024x1536'],
			['1536x1024'],
		];
	}

	/**
	 * @dataProvider ionosInvalidSizeProvider
	 */
	public function testIonosRejectsInvalidSize(string $size): void {
		$provider = $this->createProvider(
			ServiceConfig::fromArray('ionos', [
				'url' => 'https://openai.inference.de-txl.ionos.com/v1',
			]),
			'black-forest-labs/FLUX.2-Klein-4b',
		);

		$this->openAiAPIService->expects($this->never())->method('requestImageEdit');
		$this->expectException(UserFacingProcessingException::class);

		$provider->process('user', $this->validInput(['size' => $size]), static fn () => null);
	}

	/**
	 * @return list<list{string}>
	 */
	public static function ionosInvalidSizeProvider(): array {
		return [
			['2049x1024'],
			['1024x2049'],
			['1025x1024'],
			['1000x1000'],
			['32x32'],
			['4096x4096'],
		];
	}

	/**
	 * @dataProvider ionosValidSizeProvider
	 */
	public function testIonosAcceptsValidSize(string $size): void {
		$provider = $this->createProvider(
			ServiceConfig::fromArray('ionos', [
				'url' => 'https://openai.inference.de-txl.ionos.com/v1',
			]),
			'black-forest-labs/FLUX.2-Klein-4b',
		);

		$this->openAiAPIService->expects($this->once())
			->method('requestImageEdit')
			->with(
				'user',
				$this->isInstanceOf(ServiceConfig::class),
				'edit me',
				$this->isType('array'),
				'black-forest-labs/FLUX.2-Klein-4b',
				$size,
			)
			->willReturn(['data' => [['b64_json' => base64_encode('img')]]]);

		$result = $provider->process('user', $this->validInput(['size' => $size]), static fn () => null);
		$this->assertSame(['output' => 'img'], $result);
	}

	/**
	 * @return list<list{string}>
	 */
	public static function ionosValidSizeProvider(): array {
		return [
			['1024x1024'],
			['2048x2048'],
			['2048x1152'],
			['64x64'],
			['1536x1024'],
		];
	}

	public function testOtherServicesKeepMaxDimensionLimit(): void {
		$provider = $this->createProvider(
			ServiceConfig::fromArray('localai', [
				'url' => 'http://localhost:8080/v1',
			]),
			'my-image-model',
		);

		$this->openAiAPIService->expects($this->never())->method('requestImageEdit');
		$this->expectException(UserFacingProcessingException::class);

		$provider->process('user', $this->validInput(['size' => '4097x1024']), static fn () => null);
	}

	private function createProvider(ServiceConfig $service, string $model): ImageToImageProvider {
		$watermarking = $this->createMock(WatermarkingService::class);
		$watermarking->method('markImage')->willReturnArgument(0);

		return new ImageToImageProvider(
			$this->openAiAPIService,
			$this->l10n,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IClientService::class),
			$watermarking,
			$service,
			$model,
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function validInput(array $overrides = []): array {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getContent')->willReturn('png-bytes');
		$file->method('getSize')->willReturn(9);
		$file->method('getMimeType')->willReturn('image/png');

		return array_merge([
			'input' => [$file],
			'prompt' => 'edit me',
		], $overrides);
	}
}
