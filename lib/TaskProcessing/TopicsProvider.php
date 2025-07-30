<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\ChunkService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextTopics;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TopicsProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IAppConfig $appConfig,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private ChunkService $chunkService,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2text:topics';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToTextTopics::ID;
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
				EShapeType::Number
			),
			'model' => new ShapeDescriptor(
				$this->l->t('Model'),
				$this->l->t('The model used to generate the completion'),
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
		$startTime = time();

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new RuntimeException('Invalid prompt');
		}
		$prompt = $input['input'];

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID;
		}
		$prompts = $this->chunkService->chunkSplitPrompt($prompt);
		$newNumChunks = count($prompts);
		$progress = 0.0;
		$firstRun = true;
		do {
			// Make sure to run again if there is more than one chunk after the first run to remove duplicates
			$runAgain = $firstRun && $newNumChunks > 1;
			$firstRun = false;

			// Ensure that progress never finishes no matter how many times this loop runs
			$increase = (1.0 - $progress) / (float)$newNumChunks * 0.9;
			$oldNumChunks = $newNumChunks;
			$reportProgress($progress);

			try {
				$completions = [];
				if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
					$topicsSystemPrompt = 'Extract topics from the following text. Detect the language of the text. Use the same language as the text. Output only the topics, comma separated.';

					foreach ($prompts as $p) {
						$completion = $this->openAiAPIService->createChatCompletion($userId, $model, $p, $topicsSystemPrompt, null, 1, $maxTokens);
						$completions[] = $completion['messages'];
						$progress += $increase;
						$reportProgress($progress);
					}
				} else {
					$wrapTopicsPrompt = function (string $p): string {
						return 'Extract topics from the following text. Detect the language of the text. Use the same language as the text.'
							. 'Output only the topics, comma separated. Here is the text:\n\n' . $p . "\n";
					};

					foreach (array_map($wrapTopicsPrompt, $prompts) as $p) {
						$completions[] = $this->openAiAPIService->createCompletion($userId, $p, 1, $model, $maxTokens);
						$progress += $increase;
						$reportProgress($progress);
					}
				}
			} catch (Exception $e) {
				throw new RuntimeException('OpenAI/LocalAI request failed: ' . $e->getMessage());
			}

			// Each prompt chunk should return a non-empty array of completions, this will return false if at least one array is empty
			$allPromptsHaveCompletions = array_reduce($completions, fn (bool $prev, array $next): bool => $prev && count($next), true);
			if (!$allPromptsHaveCompletions) {
				throw new RuntimeException('No result in OpenAI/LocalAI response.');
			}

			// Take only one completion for each chunk and combine them into a completion
			$completionStrings = array_map(fn (array $completions): string => trim(array_pop($completions)), $completions);
			$topics = implode(', ', $completionStrings);

			$prompts = $this->chunkService->chunkSplitPrompt($topics);
			$this->logger->error('TopicsProvider(dsadsaads): ' . $topics);
			$newNumChunks = count($prompts);
		} while ($oldNumChunks > $newNumChunks || $runAgain);

		$endTime = time();
		$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
		return ['output' => $topics];
	}
}
