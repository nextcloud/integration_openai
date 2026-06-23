<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
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
use OCP\TaskProcessing\TaskTypes\TextToText;

class TextToTextProvider implements IProvider, ISynchronousOptionsAwareProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2text';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToText::ID;
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
				$this->l->t('The maximum number of words/tokens that can be generated in the completion.'),
				EShapeType::Number,
			),
			'model' => new ShapeDescriptor(
				$this->l->t('Model'),
				$this->l->t('The model used to generate the completion'),
				EShapeType::Enum,
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

	public function getOptionalOutputShape(): array {
		return [
			'reasoning' => new ShapeDescriptor(
				$this->l->t('Reasoning content'),
				$this->l->t('The model reasoning behind the output'),
				EShapeType::Text,
			),
		];
	}

	public function getOutputShapeEnumValues(): array {
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

		$startTime = time();
		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new ProcessingException('Invalid prompt');
		}
		$prompt = $input['input'];

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->openAiSettingsService->getAdminDefaultCompletionModelId();
		}

		try {
			if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
				if ($preferStreaming) {
					$chunks = $this->openAiAPIService->createStreamedChatCompletion($userId, $model, $prompt, null, null, 1, $maxTokens);
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
							$reportOutput([
								'output' => $streamedOutput,
								'reasoning' => $streamedReasoning,
							]);
							$time = microtime(true);
						}
					}
					if ($streamedOutput !== '' || $streamedReasoning !== '') {
						$reportOutput([
							'output' => $streamedOutput,
							'reasoning' => $streamedReasoning,
						]);
					}
					$returnValue = $chunks->getReturn();
					$completion = $returnValue['messages'];
					$reasoning = $returnValue['reasoning_messages'];
				} else {
					$returnValue = $this->openAiAPIService->createChatCompletion($userId, $model, $prompt, null, null, 1, $maxTokens);
					$completion = $returnValue['messages'];
					$reasoning = $returnValue['reasoning_messages'];
				}
			} else {
				$completion = $this->openAiAPIService->createCompletion($userId, $prompt, 1, $model, $maxTokens);
				$reasoning = [];
			}
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new ProcessingException('OpenAI/LocalAI request failed: ' . $e->getMessage());
		}
		if (count($completion) > 0) {
			$endTime = time();
			$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
			return [
				'output' => array_pop($completion),
				'reasoning' => count($reasoning) > 0 ? array_pop($reasoning) : '',
			];
		}

		throw new ProcessingException('No result in OpenAI/LocalAI response.');
	}
}
