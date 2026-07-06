<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use Imagick;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\Files\File;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ImageToTextOcrProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-image2text-ocr';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return ImageToTextOpticalCharacterRecognition::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime();
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		return [
			'max_tokens' => new ShapeDescriptor(
				$this->l->t('Maximum output words'),
				$this->l->t('The maximum number of words/tokens that can be generated in the output.'),
				EShapeType::Number
			),
			'model' => new ShapeDescriptor(
				$this->l->t('Model'),
				$this->l->t('The model used to generate the output'),
				EShapeType::Enum
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [
			'model' => $this->openAiAPIService->getModelEnumValues($this->userId),
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		$adminModel = $this->openAiSettingsService->getAdminDefaultCompletionModelId();
		return [
			'max_tokens' => $this->openAiSettingsService->getMaxTokens(),
			'model' => $adminModel,
		];
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

	public function process(?string $userId, array $input, callable $reportProgress): array {
		if (!$this->openAiAPIService->isUsingOpenAi() && !$this->openAiSettingsService->getChatEndpointEnabled()) {
			throw new RuntimeException('Must support chat completion endpoint');
		}

		if (!isset($input['input']) || !is_array($input['input'])) {
			throw new RuntimeException('Invalid file list');
		}
		if (count($input['input']) === 0) {
			throw new RuntimeException('Invalid file list');
		}
		if (count($input['input']) > 500) {
			throw new RuntimeException('Too many files given. Max is 500');
		}

		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->openAiSettingsService->getAdminDefaultCompletionModelId();
		}

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		$fileSize = 0;
		$outputs = [];
		$fileCount = (float)count($input['input']);
		$systemPrompt = 'Extract all visible text from the image. Return only the extracted text without additional commentary. Preserve the original language of the text.';
		$userPrompt = 'Extract all text from this image.';

		$fileIndex = 0;
		foreach ($input['input'] as $file) {
			if (!$file instanceof File || !$file->isReadable()) {
				throw new RuntimeException('Invalid input file');
			}
			$fileSize += intval($file->getSize());
			if ($fileSize > 50 * 1000 * 1000) {
				throw new UserFacingProcessingException('Filesize of input files too large. Max is 50MB', userFacingMessage: $this->l->t('Filesize of input files too large. Max is 50MB'));
			}

			$fileType = $file->getMimeType();

			if ($fileType === 'application/pdf') {
				$outputForFile = '';
				$imagickProbe = $this->getImagickProbe($file);
				$pagesRead = 0;
				foreach ($this->imagickPdfToJpegBase64($imagickProbe['image']) as $base64Image) {
					$pagesRead++;
					$history = [json_encode([
						'role' => 'user',
						'content' => [
							[
								'type' => 'image_url',
								'image_url' => [
									'url' => 'data:image/jpeg;base64,' . $base64Image,
								],
							],
						],
					])];
					try {
						$completion = $this->openAiAPIService->createChatCompletion($userId, $model, $userPrompt, $systemPrompt, $history, 1, $maxTokens);
						$messages = $completion['messages'];

						if (count($messages) === 0) {
							$this->logger->warning('No result in OpenAI/LocalAI response.');
							$outputs[] = '';
							continue;
						}

						$outputForFile .= array_pop($messages) . "\n\n";
						$reportProgress(((float)$fileIndex + (float)($pagesRead / $imagickProbe['count'])) / $fileCount);
					} catch (\Exception $e) {
						$this->logger->warning('OpenAI/LocalAI\'s OCR failed with: ' . $e->getMessage(), ['exception' => $e]);
						throw new RuntimeException('OpenAI/LocalAI\'s OCR failed with: ' . $e->getMessage());
					}
				}
				$outputs[] = $outputForFile;
				$reportProgress(((float)$fileIndex + 1.0) / $fileCount);
			} elseif (str_starts_with($fileType, 'image/')) {
				if ($this->openAiAPIService->isUsingOpenAi()) {
					$validFileTypes = [
						'image/jpeg',
						'image/png',
						'image/gif',
						'image/webp',
					];
					if (!in_array($fileType, $validFileTypes, true)) {
						throw new RuntimeException('Invalid input file type for OpenAI ' . $fileType);
					}
				}

				$base64Image = base64_encode(stream_get_contents($file->fopen('rb')));
				$history = [
					json_encode([
						'role' => 'user',
						'content' => [
							[
								'type' => 'image_url',
								'image_url' => [
									'url' => 'data:' . $fileType . ';base64,' . $base64Image,
								],
							],
						],
					]),
				];
				try {
					$completion = $this->openAiAPIService->createChatCompletion($userId, $model, $userPrompt, $systemPrompt, $history, 1, $maxTokens);
					$messages = $completion['messages'];
					$reportProgress(((float)$fileIndex + 1.0) / $fileCount);

					if (count($messages) === 0) {
						$this->logger->warning('No result in OpenAI/LocalAI response.');
						$outputs[] = '';
						continue;
					}

					$outputs[] = array_pop($messages);
				} catch (\Exception $e) {
					$this->logger->warning('OpenAI/LocalAI\'s OCR failed with: ' . $e->getMessage(), ['exception' => $e]);
					throw new RuntimeException('OpenAI/LocalAI\'s OCR failed with: ' . $e->getMessage());
				}
			} else {
				throw new UserFacingProcessingException('Only supports image and pdf file types' . $fileType, userFacingMessage: $this->l->t('Only supports image and pdf file types'));
			}
			$fileIndex++;
		}

		return ['output' => $outputs];
	}

	/**
	 * @return array{count: int, image: Imagick}
	 */
	private function getImagickProbe(File $file): array {
		if (!extension_loaded('imagick')) {
			throw new RuntimeException('Imagick extension not available can not process PDF');
		}
		if (empty(Imagick::queryFormats('PDF'))) {
			throw new RuntimeException('Imagick has no PDF support (Ghostscript missing or blocked by policy.xml)');
		}

		$pdfContent = $file->getContent();
		$probe = new Imagick();
		$probe->setResolution(200, 200);
		$probe->readImageBlob($pdfContent);
		return ['count' => $probe->getNumberImages(), 'image' => $probe];
	}
	/**
	 * @return \Generator<int, string>
	 */
	private function imagickPdfToJpegBase64(Imagick $im, int $maxPages = 100): \Generator {
		$pageCount = 0;
		foreach ($im as $page) {
			if ($pageCount >= $maxPages) {
				break;
			}
			$page = $page->getImage();
			$page->setImageBackgroundColor('white');
			$page = $page->flattenImages();
			$page->setImageFormat('jpeg');
			$page->setImageCompressionQuality(85);
			yield base64_encode($page->getImageBlob());
			$page->clear();
			$pageCount++;
		}
		$im->clear();
	}
}
