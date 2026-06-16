<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use InvalidArgumentException;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\ChunkService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
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
			// Makes sure to replace newlines and whitespace that already exists at the split
			$replaceFrom = $insertAt;
			while ($replaceFrom > 0 && preg_match('/\s/u', $result[$replaceFrom - 1]) === 1) {
				$replaceFrom--;
			}
			$result = substr($result, 0, $replaceFrom) . "\n\n" . substr($result, $insertAt);
			$delta += 2 - ($insertAt - $replaceFrom);

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
		$startTime = time();

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new InvalidArgumentException('Invalid prompt');
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
		$chunks = $this->chunkService->chunkSplitPrompt($prompt, false);
		$result = '';
		$increase = 1.0 / (float)count($chunks);
		$progress = 0.0;

		foreach ($chunks as $chunk) {
			$systemPrompt = <<<TEXT
You will receive a continuous block of text without line breaks. Your task is to identify points in the text where the subject or topic changes (e.g., a shift to a new person, place, concept, or thematic focus) and insert a line break at that specific transition.
Do NOT break lines based on sentence length or grammar unless the subject actually changes.
Once you have identified these segments, do NOT output the full text. Instead, for each new line created by a subject change, output ONLY the first 3-5 words of that line. These serve as anchors for programmatic retrieval.
Format your output as a plain list of these anchor words, one per line. Do not include numbers, bullet points, or any additional commentary.

Example input: "The market for electric vehicles is expanding rapidly. In contrast, traditional motorcycle sales are declining globally. Aside from transportation, the price of copper remains volatile."

Example output:
The market for electric vehicles
In contrast, traditional motorcycle
Aside from transportation, the price
TEXT;
			try {
				if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
					$completion = $this->openAiAPIService->createChatCompletion($userId, $model, $chunk, $systemPrompt, null, 1, $maxTokens);
					$completion = $completion['messages'];
				} else {
					$instruction = $systemPrompt . ' Here is the text:' . "\n\n" . $chunk;
					$completion = $this->openAiAPIService->createCompletion($userId, $instruction, 1, $model, $maxTokens);
				}
			} catch (UserFacingProcessingException $e) {
				throw $e;
			} catch (Exception $e) {
				throw new RuntimeException('OpenAI/LocalAI request failed: ' . $e->getMessage());
			}
			if (count($completion) > 0) {
				// The llm only needs to generate the first 8 to 12 words of each paragraph, and we get the rest of the output from the original input.
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
