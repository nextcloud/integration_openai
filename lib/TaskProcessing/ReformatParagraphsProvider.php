<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
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
use RuntimeException;

class ReformatParagraphsProvider implements ISynchronousProvider {
	private const TASK_TYPE_ID = 'core:text2text:reformatparagraphs';

	private function parseAnchorsFromModelOutput(string $raw): array {
		if ($raw === '') {
			return [];
		}

		$lines = explode("\n", $raw);
		$anchors = [];
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$anchors[] = $line;
		}
		return $anchors;
	}

	private function insertParagraphBreaksByAnchors(string $text, array $anchors): string {
		if (count($anchors) < 2) {
			return $text;
		}

		$result = $text;
		$searchOffset = 0;
		$delta = 0;
		for ($i = 1; $i < count($anchors); $i++) {
			$anchor = $anchors[$i];
			$pos = strpos($text, $anchor, $searchOffset);
			if ($pos === false) {
				continue;
			}

			$insertAt = $pos + $delta;
			$result = substr($result, 0, $insertAt) . "\n\n" . substr($result, $insertAt);
			$delta += 2;
			$searchOffset = $pos + strlen($anchor);
		}
		return $result;
	}

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IAppConfig $appConfig,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private ChunkService $chunkService,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2text:reformatparagraphs';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return self::TASK_TYPE_ID;
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
			? ($this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID, lazy: true) ?: Application::DEFAULT_MODEL_ID)
			: $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', lazy: true);
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
			$model = $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID, lazy: true) ?: Application::DEFAULT_MODEL_ID;
		}
		$chunks = $this->chunkService->chunkSplitPrompt($prompt, false);
		$result = '';
		$increase = 1.0 / (float)count($chunks);
		$progress = 0.0;

		foreach ($chunks as $chunk) {
			$systemPrompt = 'Analyze the provided text and split it into paragraphs based exclusively on thematic shifts. '
				. 'Follow these strict constraints: '
				. 'Thematic breaks only: Do not create a new paragraph for rhythm, style, or sentence flow. '
				. 'A break is allowed only when the subject matter changes significantly. '
				. 'Output format: For each identified paragraph, return only the first 8 to 12 words verbatim from the input. '
				. 'Structure: Return exactly one anchor per line. Do not include bullets, numbering, summaries, quotes, or any additional text. '
				. 'Single topic: If the text covers only one topic, return exactly one line.';
			try {
				if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
					$completion = $this->openAiAPIService->createChatCompletion($userId, $model, $chunk, $systemPrompt, null, 1, $maxTokens);
					$completion = $completion['messages'];
				} else {
					$instruction = $systemPrompt . ' Here is the text:' . "\n\n" . $chunk;
					$completion = $this->openAiAPIService->createCompletion($userId, $instruction, 1, $model, $maxTokens);
				}
			} catch (Exception $e) {
				throw new RuntimeException('OpenAI/LocalAI request failed: ' . $e->getMessage());
			}
			if (count($completion) > 0) {
				// The llm only needs to generate the first sentence of each paragraph, and we get the rest of the output from the orginal input.
				$raw = (string)array_pop($completion);
				$anchors = $this->parseAnchorsFromModelOutput($raw);
				$result .= $this->insertParagraphBreaksByAnchors($chunk, $anchors);
				$progress += $increase;
				$reportProgress($progress);
				continue;
			}

			throw new RuntimeException('No result in OpenAI/LocalAI response.');
		}

		$endTime = time();
		$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
		return ['output' => $result];
	}
}
