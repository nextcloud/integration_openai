<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use RuntimeException;

class SummaryProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IAppConfig $appConfig,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2text:summary';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToTextSummary::ID;
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

		$maxTokens = $this->openAiSettingsService->getMaxTokens();
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		$model = $this->openAiSettingsService->getAdminDefaultCompletionModelId();
		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		}

		$prompts = self::chunkSplitPrompt($prompt);
		$newNumChunks = count($prompts);

		do {
			$oldNumChunks = $newNumChunks;

			try {
				$completions = [];
				if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
					$summarySystemPrompt = 'You are a helpful assistant that summarizes text in the same language as the text. '
						. 'You should only return the summary without any additional information.';

					foreach ($prompts as $p) {
						$completion = $this->openAiAPIService->createChatCompletion($userId, $model, $p, $summarySystemPrompt, null, 1, $maxTokens);
						$completions[] = $completion['messages'];
					}
				} else {
					$wrapSummaryPrompt = function (string $p): string {
						return 'You are a helpful assistant that summarizes text in the same language as the text. '
							. 'You should only return the summary without any additional information. '
							. 'Here is the text to summarize:\n\n' . $p . '\n';
					};

					foreach (array_map($wrapSummaryPrompt, $prompts) as $p) {
						$completions[] = $this->openAiAPIService->createCompletion($userId, $p, 1, $model, $maxTokens);
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

			// Take only one completion for each chunk and combine them into a single summary (which may be used as the next prompt)
			$completionStrings = array_values(array_filter(
				array_map(fn (array $val): false|string => end($val), $completions),
				fn (false|string $val): bool => $val !== false,
			));
			$summary = implode(' ', $completionStrings);

			$prompts = self::chunkSplitPrompt($summary);
			$newNumChunks = count($prompts);
		} while ($oldNumChunks > $newNumChunks);

		$endTime = time();
		$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
		return ['output' => $summary];
	}

	private function chunkSplitPrompt(string $prompt): array {
		$chunkSize = $this->openAiSettingsService->getChunkSize();

		// https://platform.openai.com/tokenizer
		// Rough approximation, 1 token is approximately 4 bytes for OpenAI models
		// It's safer to have a lower estimate on the max number of tokens, so consider 3 bytes per token instead of 4 (to account for some multibyte characters)
		$maxChars = $chunkSize * 3;

		if (!$chunkSize || (mb_strlen($prompt) <= $maxChars)) {
			// Chunking is disabled or prompt is short enough to be a single chunk
			return [$prompt];
		}

		// Try splitting by paragraph, match as many paragraphs as possible per chunk up to the maximum chunk size
		if (preg_match_all("/.{1,{$maxChars}}\n/su", $prompt, $prompts)) {
			return $prompts[0];
		}

		// Try splitting by sentence
		if (preg_match_all("/.{1,{$maxChars}}[!\.\?\n]/su", $prompt, $prompts)) {
			return $prompts[0];
		}

		// Try splitting by word
		if (preg_match_all("/.{1,{$maxChars}}\W/su", $prompt, $prompts)) {
			return $prompts[0];
		}

		// Split by number of characters in maximum chunk size
		return mb_str_split($prompt, $maxChars);
	}
}
