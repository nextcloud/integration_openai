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
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ISynchronousOptionsAwareProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\SynchronousProviderOptions;
use OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition;
use Psr\Log\LoggerInterface;

class ImageToTextOcrProvider implements IProvider, ISynchronousOptionsAwareProvider {

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

	public function process(
		?string $userId, array $input, callable $reportProgress, SynchronousProviderOptions $options = new SynchronousProviderOptions(),
	): array {
		$reportOutput = $options->getReportIntermediateOutput();
		$preferStreaming = $options->getPreferStreaming();

		if (!$this->openAiSettingsService->isUsingOpenAi() && !$this->openAiSettingsService->getChatEndpointEnabled()) {
			throw new ProcessingException('Must support chat completion endpoint');
		}

		if (!isset($input['input']) || !is_array($input['input'])) {
			throw new ProcessingException('Invalid file list');
		}
		if (count($input['input']) === 0) {
			throw new ProcessingException('Invalid file list');
		}

		$files = $input['input'];

		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->openAiSettingsService->getAdminDefaultCompletionModelId();
		}

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		$fileSizeTotal = array_reduce(
			$files,
			function ($carry, $file) {
				return $carry + (method_exists($file, 'getSize') ? $file->getSize() : 0);
			},
			0
		);
		if ($fileSizeTotal > 50 * 1000 * 1000) {
			throw new UserFacingProcessingException(
				'Filesize of input files too large. Max is 50MB',
				0,
				null,
				$this->l->t('The total size of the input files is too large. A maximum of 50MB is allowed.'),
			);
		}

		$outputs = [];
		$streamedOutputs = [];
		$fileCount = (float)count($files);
		$systemPrompt = 'Extract all visible text from the file. Return only the extracted text without additional commentary. Preserve the original language of the text.';
		$userPrompt = 'Extract all text from this file.';

		$fileIndex = 0;
		foreach ($files as $file) {
			$streamedOutputs[] = '';
			try {
				if ($preferStreaming) {
					$chunks = $this->openAiAPIService->createStreamedChatCompletion(
						$userId, $model, $userPrompt, $systemPrompt, null, 1, $maxTokens, null, null, null, [$file],
					);
					$time = microtime(true);
					foreach ($chunks as $chunk) {
						if (!in_array($chunk['kind'] ?? null, ['content'], true)) {
							continue;
						}
						$streamedOutputs[$fileIndex] .= $chunk['text'];
						// we don't report more often than every 250ms
						if (microtime(true) - $time >= 0.25) {
							$running = $reportOutput([
								'output' => $streamedOutputs,
							]);
							if (!$running) {
								throw new ProcessingException('OpenAI/LocalAI task cancelled');
							}
							$time = microtime(true);
						}
					}
					if ($streamedOutputs[$fileIndex] !== '') {
						$running = $reportOutput([
							'output' => $streamedOutputs,
						]);
						if (!$running) {
							throw new ProcessingException('OpenAI/LocalAI task cancelled');
						}
					}
					$returnValue = $chunks->getReturn();
					$messages = $returnValue['messages'];
				} else {
					$completion = $this->openAiAPIService->createChatCompletion(
						$userId, $model, $userPrompt, $systemPrompt, null, 1, $maxTokens, null, null, null, [$file],
					);
					$messages = $completion['messages'];
				}
				$reportProgress(((float)$fileIndex + 1.0) / $fileCount);

				if (count($messages) === 0) {
					$this->logger->warning('No result in OpenAI/LocalAI response.');
					$outputs[] = '';
					$fileIndex++;
					continue;
				}

				$outputs[] = array_pop($messages);
			} catch (UserFacingProcessingException|ProcessingException $e) {
				throw $e;
			} catch (\Throwable $e) {
				$this->logger->warning('OpenAI/LocalAI\'s OCR failed with: ' . $e->getMessage(), ['exception' => $e]);
				throw new ProcessingException('OpenAI/LocalAI\'s OCR failed with: ' . $e->getMessage());
			}
			$fileIndex++;
		}

		return ['output' => $outputs];
	}
}
