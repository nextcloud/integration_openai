<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\Service\ChunkService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServiceConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ISynchronousOptionsAwareProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\SynchronousProviderOptions;
use OCP\TaskProcessing\TaskTypes\ContextWrite;

class ContextWriteProvider implements IProvider, ISynchronousOptionsAwareProvider {
	use ProviderIdentity;

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private ChunkService $chunkService,
		private IL10N $l,
		private ServiceConfig $service,
		private string $model,
	) {
	}

	public function getId(): string {
		return $this->buildProviderId('contextwrite');
	}

	public function getName(): string {
		return $this->buildProviderName();
	}

	public function getTaskTypeId(): string {
		return ContextWrite::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime($this->service);
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
				$this->l->t('The maximum number of words/tokens that can be generated in the completion.'),
				EShapeType::Number
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [
			'max_tokens' => $this->service->getMaxTokens(),
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
		$startTime = time();

		if (
			!isset($input['style_input']) || !is_string($input['style_input'])
				|| !isset($input['source_input']) || !is_string($input['source_input'])
		) {
			throw new ProcessingException('Invalid inputs');
		}

		$writingStyle = $input['style_input'];
		$sourceMaterial = $input['source_input'];

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		$model = $this->model;

		$chunks = $this->chunkService->chunkSplitPrompt($this->service, $sourceMaterial, true, $maxTokens);
		$fullOutput = '';
		$fullReasoning = '';

		$increase = 1.0 / (float)count($chunks);
		$progress = 0.0;
		$streamedOutput = '';
		$streamedReasoning = '';

		foreach ($chunks as $sourceMaterial) {
			$prompt = 'You\'re a professional copywriter tasked with copying an instructed or demonstrated *WRITING STYLE*'
				. ' and writing a text on the provided *SOURCE MATERIAL*.'
				. " \n*WRITING STYLE*:\n$writingStyle\n\n*SOURCE MATERIAL*:\n\n$sourceMaterial\n\n"
				. 'Now write a text in the same style detailed or demonstrated under *WRITING STYLE* using the *SOURCE MATERIAL*'
				. ' as source of facts and instruction on what to write about.'
				. ' Do not invent any facts or events yourself.'
				. ' Also, use the *WRITING STYLE* as a guide for how to write the text ONLY and not as a source of facts or events.'
				. ' Detect the language used in the *SOURCE_MATERIAL*. Make sure to use the same language in your response. Do not mention the language explicitly.';
			try {
				if ($this->service->isUsingOpenAi() || $this->service->getChatEndpointEnabled()) {
					if ($preferStreaming) {
						$chunks = $this->openAiAPIService->createStreamedChatCompletion($userId, $this->service, $model, $prompt, null, null, 1, $maxTokens);
						$time = microtime(true);
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
						$returnValue = $this->openAiAPIService->createChatCompletion($userId, $this->service, $model, $prompt, null, null, 1, $maxTokens);
						$completion = $returnValue['messages'];
						$reasoning = $returnValue['reasoning_messages'];
					}
				} else {
					$completion = $this->openAiAPIService->createCompletion($userId, $this->service, $prompt, 1, $model, $maxTokens);
					$reasoning = [];
				}
			} catch (UserFacingProcessingException $e) {
				throw $e;
			} catch (\Throwable $e) {
				throw new ProcessingException('OpenAI/LocalAI request failed: ' . $e->getMessage());
			}
			if (count($reasoning) > 0) {
				$fullReasoning .= array_pop($reasoning);
			}
			if (count($completion) > 0) {
				$fullOutput .= array_pop($completion);
				$progress += $increase;
				$running = $reportProgress($progress);
				if (!$running) {
					throw new ProcessingException('OpenAI/LocalAI task cancelled');
				}
				continue;
			}

			throw new ProcessingException('No result in OpenAI/LocalAI response.');
		}
		$endTime = time();
		$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime, $this->service);
		return [
			'output' => $fullOutput,
			'reasoning' => $fullReasoning,
		];
	}
}
