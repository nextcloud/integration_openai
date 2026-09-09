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
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Transcribes audio and reformats the transcription into paragraphs.
 *
 * Both steps run on the same service: the transcription with the
 * speech-to-text model this provider is registered for, the reformatting with
 * the first text model selected for that service.
 */
class AudioToTextEnhancedProvider implements ISynchronousProvider {
	// No ProviderIdentity: this provider derives its ID and name from the
	// transcription provider it wraps rather than from a model of its own.

	public function __construct(
		private AudioToTextProvider $audioToTextProvider,
		private ReformatParagraphsProvider $reformatParagraphsProvider,
		private OpenAiAPIService $openAiAPIService,
		private LoggerInterface $logger,
		private IL10N $l,
		private ServiceConfig $service,
	) {
	}

	public function getId(): string {
		return $this->audioToTextProvider->getId() . '-enhanced';
	}

	public function getName(): string {
		return $this->l->t('%s (with paragraph reformatting)', [$this->audioToTextProvider->getName()]);
	}

	public function getTaskTypeId(): string {
		return AudioToText::ID;
	}

	public function getExpectedRuntime(): int {
		// The audio to text provider may not be openai and this assumes it is
		return $this->audioToTextProvider->getExpectedRuntime() + $this->openAiAPIService->getExpTextProcessingTime($this->service);
	}

	public function getInputShapeEnumValues(): array {
		return $this->audioToTextProvider->getInputShapeEnumValues();
	}

	public function getInputShapeDefaults(): array {
		return $this->audioToTextProvider->getInputShapeDefaults();
	}

	public function getOptionalInputShape(): array {
		return $this->audioToTextProvider->getOptionalInputShape();
	}

	public function getOptionalInputShapeEnumValues(): array {
		return $this->audioToTextProvider->getOptionalInputShapeEnumValues();
	}

	public function getOptionalInputShapeDefaults(): array {
		return $this->audioToTextProvider->getOptionalInputShapeDefaults();
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
		$transcription = $this->audioToTextProvider->process($userId, $input, $reportProgress)['output'];

		// Skip reformatting if the transcription is empty
		if (trim($transcription) === '') {
			return ['output' => $transcription];
		}

		try {
			$output = $this->reformatParagraphsProvider->process($userId, ['input' => $transcription], $reportProgress);
			if (isset($output['output']) && is_string($output['output']) && $output['output'] !== '') {
				return ['output' => $output['output']];
			}
			$this->logger->warning('Paragraph reformatting returned no usable output, falling back to raw transcription');
		} catch (Throwable $e) {
			$this->logger->warning('Paragraph reformatting failed, falling back to raw transcription: ' . $e->getMessage(), ['exception' => $e]);
		}

		return ['output' => $transcription];
	}
}
