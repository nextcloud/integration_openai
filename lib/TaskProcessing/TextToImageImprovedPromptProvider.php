<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousWatermarkingProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use OCP\TaskProcessing\TaskTypes\TextToText;
use Psr\Log\LoggerInterface;
use Throwable;

class TextToImageImprovedPromptProvider implements ISynchronousWatermarkingProvider {
	public function __construct(
		private TextToImageProvider $textToImageProvider,
		private IManager $taskProcessingManager,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private OpenAiAPIService $openAiAPIService,
	) {
	}

	public function getId(): string {
		return $this->textToImageProvider->getId() . '-improved-prompt';
	}

	public function getName(): string {
		return $this->l10n->t('%s (LLM-improved prompt)', [$this->textToImageProvider->getName()]);
	}

	public function getTaskTypeId(): string {
		return TextToImage::ID;
	}

	public function getExpectedRuntime(): int {
		// The text to image provider may not be openai and this assumes it is
		return $this->textToImageProvider->getExpectedRuntime() + $this->openAiAPIService->getExpTextProcessingTime();
	}

	public function getInputShapeEnumValues(): array {
		return $this->textToImageProvider->getInputShapeEnumValues();
	}

	public function getInputShapeDefaults(): array {
		return $this->textToImageProvider->getInputShapeDefaults();
	}

	public function getOptionalInputShape(): array {
		return $this->textToImageProvider->getOptionalInputShape();
	}

	public function getOptionalInputShapeEnumValues(): array {
		return $this->textToImageProvider->getOptionalInputShapeEnumValues();
	}

	public function getOptionalInputShapeDefaults(): array {
		return $this->textToImageProvider->getOptionalInputShapeDefaults();
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [
			'enhanced_prompts' => new ShapeDescriptor(
				$this->l10n->t('Improved prompts'),
				$this->l10n->t('The prompts after LLM enhancement, one per generated image, as sent to the image model.'),
				EShapeType::ListOfTexts
			),
		];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress, bool $includeWatermark = true): array {
		if (!isset($input['input']) || !is_string($input['input']) || trim($input['input']) === '') {
			return $this->textToImageProvider->process($userId, $input, $reportProgress, $includeWatermark);
		}

		$originalPrompt = $input['input'];
		$nbImages = 1;
		if (isset($input['numberOfImages']) && is_int($input['numberOfImages'])) {
			$nbImages = $input['numberOfImages'];
		}
		if ($nbImages < 1 || $nbImages > 12) {
			throw new UserFacingProcessingException(
				'Invalid number of images',
				0,
				null,
				$this->l10n->t('Invalid number of images'),
			);
		}

		$instruction
			= 'Improve the following image-generation prompt into exactly ' . $nbImages . ' DISTINCT variant(s) '
			. 'so a text-to-image model produces high quality, visually rich, coherent images. '
			. 'Each variant must keep the original intent but differ in concrete visual details '
			. '(composition, lighting, style, camera angle, mood, etc.) so the resulting images are meaningfully different. '
			. 'Add concrete visual details only when they are reasonable. '
			. 'Return ONLY a JSON array of exactly ' . $nbImages . ' strings (the improved prompts), '
			. 'no preface, no markdown, no quotes around the JSON, no explanations.' . "\n\n"
			. 'Original prompt:' . "\n" . $originalPrompt;
		$improveTask = new Task(
			TextToText::ID,
			['input' => $instruction],
			Application::APP_ID,
			$userId,
			'text2image_improved_prompt',
		);

		$improvedPrompts = array_fill(0, $nbImages, $originalPrompt);
		try {
			$finished = $this->taskProcessingManager->runTask($improveTask);
			$output = $finished->getOutput();
			if (is_array($output) && isset($output['output']) && is_string($output['output']) && trim($output['output']) !== '') {
				$parsed = $this->parseImprovedPrompts(trim($output['output']), $nbImages, $originalPrompt);
				if ($parsed !== null) {
					$improvedPrompts = $parsed;
				} else {
					$this->logger->warning('Prompt improvement task returned no usable output, falling back to original prompt');
				}
			} else {
				$this->logger->warning('Prompt improvement task returned no usable output, falling back to original prompt');
			}
		} catch (Throwable $e) {
			$this->logger->warning('Prompt improvement task failed, falling back to original prompt: ' . $e->getMessage(), ['exception' => $e]);
		}
		$enhancedPromptProgressBase = 0.3;
		$reportProgress($enhancedPromptProgressBase);

		$images = [];
		for ($i = 0; $i < $nbImages; $i++) {
			$newInput = $input;
			$newInput['input'] = $improvedPrompts[$i];
			$newInput['numberOfImages'] = 1;
			$result = $this->textToImageProvider->process(
				$userId,
				$newInput,
				static function (float $progress): bool {
					return true;
				},
				$includeWatermark,
			);
			$reportProgress($enhancedPromptProgressBase + (0.7 * (float)($i + 1) / (float)$nbImages));
			if (isset($result['images']) && is_array($result['images'])) {
				array_push($images, ...$result['images']);
			}
		}

		return [
			'images' => $images,
			'enhanced_prompts' => $improvedPrompts,
		];
	}

	/**
	 * @return list<string>|null
	 */
	private function parseImprovedPrompts(string $rawOutput, int $nbImages, string $fallback): ?array {
		// Remove everything before the first "[" and after the last "]"
		if (($firstBracket = strpos($rawOutput, '[')) !== false && ($lastBracket = strrpos($rawOutput, ']')) !== false && $lastBracket > $firstBracket) {
			$rawOutput = substr($rawOutput, $firstBracket, $lastBracket - $firstBracket + 1);
		}

		$decoded = json_decode($rawOutput, true);
		if (!is_array($decoded)) {
			return null;
		}

		$prompts = [];
		foreach ($decoded as $item) {
			if (is_string($item) && trim($item) !== '') {
				$prompts[] = trim($item);
			}
		}

		if (count($prompts) >= $nbImages) {
			return array_slice($prompts, 0, $nbImages);
		}

		while (count($prompts) < $nbImages) {
			$prompts[] = $fallback;
		}
		return $prompts;
	}
}
