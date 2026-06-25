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
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ISynchronousOptionsAwareProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\SynchronousProviderOptions;
use Psr\Log\LoggerInterface;

class AnalyzeImagesProvider implements IProvider, ISynchronousOptionsAwareProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private LoggerInterface $logger,
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
		return \OCP\TaskProcessing\TaskTypes\AnalyzeImages::ID;
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
		return [
			'reasoning' => new ShapeDescriptor(
				$this->l->t('Reasoning content'),
				$this->l->t('The model reasoning behind the output'),
				EShapeType::Text,
			),
		];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(
		?string $userId, array $input, callable $reportProgress, SynchronousProviderOptions $options = new SynchronousProviderOptions(),
	): array {
		$reportOutput = $options->getReportIntermediateOutput();
		$preferStreaming = $options->getPreferStreaming();

		if (!$this->openAiAPIService->isUsingOpenAi() && !$this->openAiSettingsService->getChatEndpointEnabled()) {
			throw new ProcessingException('Must support chat completion endpoint');
		}

		$history = [];

		if (!isset($input['images']) || !is_array($input['images'])) {
			throw new ProcessingException('Invalid file list');
		}
		// Maximum file count for openai is 500. Seems reasonable enough to enforce for all apis though (https://platform.openai.com/docs/guides/images-vision?api-mode=responses&format=url#image-input-requirements)
		if (count($input['images']) > 500) {
			throw new UserFacingProcessingException(
				'Too many files given. Max is 500',
				0,
				null,
				$this->l->t('Too many files given. A maximum of 500 files is allowed.'),
			);
		}
		$fileSize = 0;
		foreach ($input['images'] as $image) {
			if (!$image instanceof File || !$image->isReadable()) {
				throw new ProcessingException('Invalid input file');
			}
			$fileSize += intval($image->getSize());
			// Maximum file size for openai is 50MB. Seems reasonable enough to enforce for all apis though. (https://platform.openai.com/docs/guides/images-vision?api-mode=responses&format=url#image-input-requirements)
			if ($fileSize > 50 * 1000 * 1000) {
				throw new UserFacingProcessingException(
					'Filesize of input files too large. Max is 50MB',
					0,
					null,
					$this->l->t('The total size of the input files is too large. A maximum of 50MB is allowed.'),
				);
			}
			$inputFile = base64_encode(stream_get_contents($image->fopen('rb')));
			$fileType = $image->getMimeType();
			if (!str_starts_with($fileType, 'image/')) {
				throw new UserFacingProcessingException(
					'Invalid input file type ' . $fileType,
					0,
					null,
					$this->l->t('Invalid input file type "%1$s". Only image files are supported.', [$fileType]),
				);
			}
			if ($this->openAiAPIService->isUsingOpenAi()) {
				$validFileTypes = [
					'image/jpeg',
					'image/png',
					'image/gif',
					'image/webp',
				];
				if (!in_array($fileType, $validFileTypes)) {
					throw new UserFacingProcessingException(
						'Invalid input file type for OpenAI ' . $fileType,
						0,
						null,
						$this->l->t('Invalid input file type "%1$s". Only JPEG, PNG, GIF and WebP images are supported.', [$fileType]),
					);
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
			throw new ProcessingException('Invalid prompt');
		}
		$prompt = $input['input'];

		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->openAiSettingsService->getAdminDefaultCompletionModelId();
		}

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		try {
			$systemPrompt = 'Take the user\'s question and answer it based on the provided images. Ensure that the answer matches the language of the user\'s question.';
			if ($preferStreaming) {
				$chunks = $this->openAiAPIService->createStreamedChatCompletion($userId, $model, $prompt, $systemPrompt, $history, 1, $maxTokens);
				$time = microtime(true);
				$streamedOutput = '';
				$streamedReasoning = '';
				foreach ($chunks as $chunk) {
					if (!in_array($chunk['kind'] ?? null, ['content', 'reasoning_content'], true)) {
						continue;
					}
					if ($chunk['kind'] === 'reasoning_content') {
						$streamedReasoning .= $chunk['text'];
					} elseif ($chunk['kind'] === 'content') {
						$streamedOutput .= $chunk['text'];
					}
					// we don't report more often than every 250ms
					if (microtime(true) - $time >= 0.25) {
						$running = $reportOutput([
							'output' => $streamedOutput,
							'reasoning' => $streamedReasoning,
						]);
						if (!$running) {
							throw new ProcessingException('OpenAI/LocalAI task cancelled');
						}
						$time = microtime(true);
					}
				}
				if ($streamedOutput !== '' || $streamedReasoning !== '') {
					$running = $reportOutput([
						'output' => $streamedOutput,
						'reasoning' => $streamedReasoning,
					]);
					if (!$running) {
						throw new ProcessingException('OpenAI/LocalAI task cancelled');
					}
				}
				$returnValue = $chunks->getReturn();
				$completion = $returnValue['messages'];
				$reasoning = $returnValue['reasoning_messages'];
			} else {
				$returnValue = $this->openAiAPIService->createChatCompletion($userId, $model, $prompt, $systemPrompt, $history, 1, $maxTokens);
				$completion = $returnValue['messages'];
				$reasoning = $returnValue['reasoning_messages'];
			}

			if (count($completion) > 0) {
				return [
					'output' => array_pop($completion),
					'reasoning' => count($reasoning) > 0 ? array_pop($reasoning) : '',
				];
			}

			throw new ProcessingException('No result in OpenAI/LocalAI response.');
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('OpenAI/LocalAI\'s image question generation failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException('OpenAI/LocalAI\'s image question generation failed with: ' . $e->getMessage());
		}
	}
}
