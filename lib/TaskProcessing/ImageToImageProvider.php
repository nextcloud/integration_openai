<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServiceConfig;
use OCA\OpenAi\Service\WatermarkingService;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\ISynchronousOptionsAwareProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\SynchronousProviderOptions;
use Psr\Log\LoggerInterface;

class ImageToImageProvider implements ISynchronousOptionsAwareProvider {
	use ProviderIdentity;

	private const VALID_IMAGE_MIME_TYPES = [
		'image/jpeg',
		'image/png',
		'image/webp',
	];

	private const MAX_FILE_SIZE_BYTES = 25_000_000;

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IL10N $l,
		private LoggerInterface $logger,
		private IClientService $clientService,
		private WatermarkingService $watermarkingService,
		private ServiceConfig $service,
		private string $model,
	) {
	}

	public function getId(): string {
		return $this->buildProviderId();
	}

	public function getName(): string {
		return $this->buildProviderName();
	}

	public function getTaskTypeId(): string {
		if (class_exists('OCP\\TaskProcessing\\TaskTypes\\ImageToImage')) {
			return \OCP\TaskProcessing\TaskTypes\ImageToImage::ID;
		}
		return ImageToImageTaskType::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpImgProcessingTime($this->service);
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		$defaultImageSize = $this->service->getDefaultImageSize();
		return [
			'size' => new ShapeDescriptor(
				$this->l->t('Size'),
				$this->l->t('Optional. The size of the generated images. Must be in 256x256 format. Default is %s', [$defaultImageSize]),
				EShapeType::Text
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(
		?string $userId,
		array $input,
		callable $reportProgress,
		SynchronousProviderOptions $options = new SynchronousProviderOptions(),
	): array {
		$startTime = time();
		$includeWatermark = $options->getIncludeWatermarks();

		if (!isset($input['input']) || !is_array($input['input']) || $input['input'] === []) {
			throw new ProcessingException('Invalid input files');
		}

		if (!isset($input['prompt']) || !is_string($input['prompt'])) {
			throw new ProcessingException('Invalid prompt');
		}
		$prompt = $input['prompt'];

		$images = [];

		if (count($input['input']) > 16) {
			throw new UserFacingProcessingException(
				'Too many input images. Max is 16',
				0,
				null,
				$this->l->t('Cannot use more than 16 input images.'),
			);
		}

		foreach ($input['input'] as $inputFile) {
			if (!$inputFile instanceof File || !$inputFile->isReadable()) {
				throw new ProcessingException('Invalid input file');
			}
			if ($inputFile->getSize() > self::MAX_FILE_SIZE_BYTES) {
				throw new UserFacingProcessingException(
					'Filesize of input file too large. Max is 25MB',
					0,
					null,
					$this->l->t('The size of the input file is too large. A maximum of 25MB is allowed.'),
				);
			}

			$mimeType = $inputFile->getMimeType();
			if (!in_array($mimeType, self::VALID_IMAGE_MIME_TYPES, true)) {
				throw new UserFacingProcessingException(
					'Invalid input file type for OpenAI ' . $mimeType,
					0,
					null,
					$this->l->t('Invalid input file type "%1$s".', [$mimeType]),
				);
			}

			$images[] = [
				'content' => $inputFile->getContent(),
				'mimeType' => $mimeType,
			];
		}

		$size = $this->service->getDefaultImageSize();
		if (isset($input['size']) && is_string($input['size']) && preg_match('/^\d+x\d+$/', $input['size'])) {
			$size = trim($input['size']);
		}
		if (preg_match('/^\d+x\d+$/', $size) !== 1) {
			$size = Application::DEFAULT_DEFAULT_IMAGE_SIZE;
		}
		[$x, $y] = explode('x', $size, 2);
		if ((int)$x > 4096 || (int)$y > 4096) {
			throw new UserFacingProcessingException('size is out of bounds', userFacingMessage: $this->l->t('Cannot generate images larger than 4096x4096'));
		}

		try {
			$apiResponse = $this->openAiAPIService->requestImageEdit(
				$userId,
				$this->service,
				$prompt,
				$images,
				$this->model,
				$size,
			);
			$b64s = array_map(static function (array $result) {
				return $result['b64_json'] ?? null;
			}, $apiResponse['data']);
			$b64s = array_values(array_filter($b64s, static function (?string $b64) {
				return $b64 !== null;
			}));

			$urls = array_map(static function (array $result) {
				return $result['url'] ?? null;
			}, $apiResponse['data']);
			$urls = array_values(array_filter($urls, static function (?string $url) {
				return $url !== null;
			}));

			if (empty($urls) && empty($b64s)) {
				$this->logger->warning('OpenAI/LocalAI\'s image to image generation failed: no image returned');
				throw new ProcessingException('OpenAI/LocalAI\'s image to image generation failed: no image returned');
			}

			$image = null;
			if (!empty($urls)) {
				$client = $this->clientService->newClient();
				$requestOptions = $this->openAiAPIService->getImageRequestOptions($userId, $this->service);
				$imageResponse = $client->get($urls[0], $requestOptions);
				$image = $imageResponse->getBody();
			} else {
				$image = base64_decode($b64s[0]);
			}

			$image = $includeWatermark ? $this->watermarkingService->markImage($image) : $image;
			$endTime = time();
			$this->openAiAPIService->updateExpImgProcessingTime($endTime - $startTime, $this->service);
			return ['output' => $image];
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('OpenAI/LocalAI\'s image to image generation failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException('OpenAI/LocalAI\'s image to image generation failed with: ' . $e->getMessage());
		}
	}
}
