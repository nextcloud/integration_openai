<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServiceConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousWatermarkingProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generates an image from a prompt that an LLM improved first.
 *
 * Both steps run on the same service: the image with the model this provider
 * is registered for, the prompt improvement with the first text model selected
 * for that service.
 */
class TextToImageImprovedPromptProvider implements ISynchronousWatermarkingProvider {
	// No ProviderIdentity: this provider derives its ID and name from the
	// image provider it wraps rather than from a model of its own.

	public function __construct(
		private TextToImageProvider $textToImageProvider,
		private TextToTextProvider $textToTextProvider,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private OpenAiAPIService $openAiAPIService,
		private ServiceConfig $service,
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
		return $this->textToImageProvider->getExpectedRuntime() + $this->openAiAPIService->getExpTextProcessingTime($this->service);
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
			'enhanced_prompt' => new ShapeDescriptor(
				$this->l10n->t('Improved prompt'),
				$this->l10n->t('The prompt after LLM enhancement, as sent to the image model.'),
				EShapeType::Text
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
		$instruction
			= 'Improve the following image-generation prompt so a text-to-image model produces a high quality, visually rich, coherent image. '
			. 'Add concrete visual details (subject, composition, lighting, style) only when they are reasonable. '
			. 'Keep the original intent. Return ONLY the improved prompt as a single line, no preface, no quotes, no explanations.' . "\n\n"
			. 'Original prompt:' . "\n" . $originalPrompt;
		$improvedPrompt = $originalPrompt;
		try {
			$output = $this->textToTextProvider->process($userId, ['input' => $instruction], $reportProgress);
			if (isset($output['output']) && is_string($output['output']) && trim($output['output']) !== '') {
				$improvedPrompt = trim($output['output']);
			} else {
				$this->logger->warning('Prompt improvement returned no usable output, falling back to original prompt');
			}
		} catch (Throwable $e) {
			$this->logger->warning('Prompt improvement failed, falling back to original prompt: ' . $e->getMessage(), ['exception' => $e]);
		}
		$reportProgress(0.5);

		$newInput = $input;
		$newInput['input'] = $improvedPrompt;
		$output = $this->textToImageProvider->process($userId, $newInput, $reportProgress, $includeWatermark);
		$output['enhanced_prompt'] = $improvedPrompt;
		return $output;
	}
}
