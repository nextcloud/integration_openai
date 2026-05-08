<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCP\IL10N;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousWatermarkingProvider;
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
		return $this->textToImageProvider->getExpectedRuntime();
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
		return $this->textToImageProvider->getOutputShapeEnumValues();
	}

	public function getOptionalOutputShape(): array {
		return $this->textToImageProvider->getOptionalOutputShape();
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return $this->textToImageProvider->getOptionalOutputShapeEnumValues();
	}

	public function process(?string $userId, array $input, callable $reportProgress, bool $includeWatermark = true): array {
		if (!isset($input['input']) || !is_string($input['input']) || trim($input['input']) === '') {
			return $this->textToImageProvider->process($userId, $input, $reportProgress, $includeWatermark);
		}

		$originalPrompt = $input['input'];
		$instruction
			= 'Improve the following image-generation prompt so a text-to-image model produces a high quality, visually rich, coherent image. '
			. 'Add concrete visual details (subject, composition, lighting, style) only when they are reasonable. '
			. 'Keep the original intent. Return ONLY the improved prompt as a single line, no preface, no quotes, no explanations.' . "\n\n"
			. 'Original prompt:' . "\n" . $originalPrompt;
		$improveTask = new Task(
			TextToText::ID,
			['input' => $instruction],
			Application::APP_ID,
			$userId,
			'text2image_improved_prompt',
		);

		$improvedPrompt = $originalPrompt;
		try {
			$finished = $this->taskProcessingManager->runTask($improveTask);
			$output = $finished->getOutput();
			if (is_array($output) && isset($output['output']) && is_string($output['output']) && trim($output['output']) !== '') {
				$improvedPrompt = trim($output['output']);
			} else {
				$this->logger->warning('Prompt improvement task returned no usable output, falling back to original prompt');
			}
		} catch (Throwable $e) {
			$this->logger->warning('Prompt improvement task failed, falling back to original prompt: ' . $e->getMessage(), ['exception' => $e]);
		}
		$reportProgress(0.5);

		$newInput = $input;
		$newInput['input'] = $improvedPrompt;
		return $this->textToImageProvider->process($userId, $newInput, $reportProgress, $includeWatermark);
	}
}
