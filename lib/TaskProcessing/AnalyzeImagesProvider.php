<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
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
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AnalyzeImagesProvider implements ISynchronousProvider {

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
		return Application::APP_ID . '-analyze-images';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		if (class_exists('OCP\\TaskProcessing\\TaskTypes\\AnalyzeImages')) {
			return \OCP\TaskProcessing\TaskTypes\AnalyzeImages::ID;
		}
		return AnalyzeImagesTaskType::ID;
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
		$adminModel = $this->openAiAPIService->isUsingOpenAi()
			? ($this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID)
			: $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id');
		return [
			'max_tokens' => 1000,
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

		$history = [];

		if (!isset($input['images']) || !is_array($input['images'])) {
			throw new RuntimeException('Invalid file list');
		}
		// Maximum file count for openai is 500. Seems reasonable enough to enforce for all apis though (https://platform.openai.com/docs/guides/images-vision?api-mode=responses&format=url#image-input-requirements)
		if (count($input['images']) > 500) {
			throw new RuntimeException('Too many files given. Max is 100');
		}
		$fileSize = 0;
		foreach ($input['images'] as $image) {
			if (!$image instanceof File || !$image->isReadable()) {
				throw new RuntimeException('Invalid input file');
			}
			$fileSize += intval($image->getSize());
			// Maximum file size for openai is 50MB. Seems reasonable enough to enforce for all apis though. (https://platform.openai.com/docs/guides/images-vision?api-mode=responses&format=url#image-input-requirements)
			if ($fileSize > 50 * 1000 * 1000) {
				throw new RuntimeException('Filesize of input files too large. Max is 50MB');
			}
			$inputFile = base64_encode(stream_get_contents($image->fopen('rb')));
			$fileType = $image->getMimeType();
			if (!str_starts_with($fileType, 'image/')) {
				throw new RuntimeException('Invalid input file type ' . $fileType);
			}
			if ($this->openAiAPIService->isUsingOpenAi()) {
				$validFileTypes = [
					'image/jpeg',
					'image/png',
					'image/gif',
					'image/webp',
				];
				if (!in_array($fileType, $validFileTypes)) {
					throw new RuntimeException('Invalid input file type for OpenAI ' . $fileType);
				}
			}
			$history[] = json_encode([
				'role' => 'user',
				'content' => [
					[
						'type' => 'image_url',
						'image_url' => [
							'url' => 'data:' . $fileType . ';base64,' . $inputFile,
						],
					],
				],
			]);
		}


		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new RuntimeException('Invalid prompt');
		}
		$prompt = $input['input'];

		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;
		}

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		try {
			$systemPrompt = 'Take the user\'s question and answer it based on the provided images. Ensure that the answer matches the language of the user\'s question.';
			$completion = $this->openAiAPIService->createChatCompletion($userId, $model, $prompt, $systemPrompt, $history, 1, $maxTokens);
			$completion = $completion['messages'];

			if (count($completion) > 0) {
				return ['output' => array_pop($completion)];
			}

			throw new RuntimeException('No result in OpenAI/LocalAI response.');
		} catch (\Exception $e) {
			$this->logger->warning('OpenAI/LocalAI\'s image question generation failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new RuntimeException('OpenAI/LocalAI\'s image question generation failed with: ' . $e->getMessage());
		}
	}
}
