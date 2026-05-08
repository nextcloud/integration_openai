<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use Psr\Log\LoggerInterface;
use Throwable;

class AudioToTextEnhancedProvider implements ISynchronousProvider {

	public function __construct(
		private AudioToTextProvider $audioToTextProvider,
		private OpenAiAPIService $openAiAPIService,
		private IManager $taskProcessingManager,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return $this->audioToTextProvider->getId() . '-enhanced';
	}

	public function getName(): string {
		return $this->audioToTextProvider->getName() . ' (with paragraph reformatting)';
	}

	public function getTaskTypeId(): string {
		return AudioToText::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->audioToTextProvider->getExpectedRuntime();
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
		return $this->audioToTextProvider->getOutputShapeEnumValues();
	}

	public function getOptionalOutputShape(): array {
		return $this->audioToTextProvider->getOptionalOutputShape();
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return $this->audioToTextProvider->getOptionalOutputShapeEnumValues();
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$transcription = $this->audioToTextProvider->process($userId, $input, $reportProgress)['output'];

		// Skip reformatting if the transcription is empty
		if (trim($transcription) === '') {
			return ['output' => $transcription];
		}

		$reformatTask = new Task(
			\OCP\TaskProcessing\TaskTypes\TextToTextReformatParagraphs::ID,
			['input' => $transcription],
			Application::APP_ID,
			$userId,
			'audio2text_enhanced',
		);

		try {
			$finished = $this->taskProcessingManager->runTask($reformatTask);
			$output = $finished->getOutput();
			if (is_array($output) && isset($output['output']) && is_string($output['output']) && $output['output'] !== '') {
				return ['output' => $output['output']];
			}
			$this->logger->warning('ReformatParagraphs follow-up task returned no usable output, falling back to raw transcription');
		} catch (Throwable $e) {
			$this->logger->warning('ReformatParagraphs follow-up task failed, falling back to raw transcription: ' . $e->getMessage(), ['exception' => $e]);
		}

		return ['output' => $transcription];
	}
}
