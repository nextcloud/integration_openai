<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\Files\File;
use OCP\IAppConfig;
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
		private IAppConfig $appConfig,
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
		$systemPrompt = 'Extract all visible text from the image. Return only the extracted text without additional commentary. Preserve the original language of the text.';
		$userPrompt = 'Extract all text from this image.';

		foreach ($input['input'] as $i => $file) {
			if (!$file instanceof File || !$file->isReadable()) {
				throw new RuntimeException('Invalid input file');
			}
			$fileSize += intval($file->getSize());
			if ($fileSize > 50 * 1000 * 1000) {
				throw new UserFacingProcessingException('Filesize of input files too large. Max is 50MB', userFacingMessage: $this->l->t('Filesize of input files too large. Max is 50MB'));
			}

			$fileType = $file->getMimeType();
			if (!str_starts_with($fileType, 'image/')) {
				throw new UserFacingProcessingException('Only supports image file types' . $fileType, userFacingMessage: $this->l->t('Only supports image file types'));
			}
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

			$inputFile = base64_encode(stream_get_contents($file->fopen('rb')));
			$history = [
				json_encode([
					'role' => 'user',
					'content' => [
						[
							'type' => 'image_url',
							'image_url' => [
								'url' => 'data:' . $fileType . ';base64,' . $inputFile,
							],
						],
					],
				]),
			];

			try {
				$completion = $this->openAiAPIService->createChatCompletion($userId, $model, $userPrompt, $systemPrompt, $history, 1, $maxTokens);
				$messages = $completion['messages'];

				if (count($messages) === 0) {
					throw new RuntimeException('No result in OpenAI/LocalAI response.');
				}

				$outputs[] = array_pop($messages);
				$reportProgress(($i + 1) / count($input['input']));
			} catch (\Exception $e) {
				$this->logger->warning('OpenAI/LocalAI\'s OCR failed with: ' . $e->getMessage(), ['exception' => $e]);
				throw new RuntimeException('OpenAI/LocalAI\'s OCR failed with: ' . $e->getMessage());
			}
		}

		return ['output' => $outputs];
	}
}
