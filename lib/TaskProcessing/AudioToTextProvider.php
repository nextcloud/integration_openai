<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\TaskProcessing;

use Exception;
use OCA\Watsonx\AppInfo\Application;
use OCA\Watsonx\Service\WatsonxAPIService;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AudioToTextProvider implements ISynchronousProvider {

	public function __construct(
		private WatsonxAPIService $watsonxAPIService,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-audio2text';
	}

	public function getName(): string {
		return $this->watsonxAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return AudioToText::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->watsonxAPIService->getExpTextProcessingTime();
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		return [];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [];
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
		if (!isset($input['input']) || !$input['input'] instanceof File || !$input['input']->isReadable()) {
			throw new RuntimeException('Invalid input file');
		}
		$inputFile = $input['input'];

		$model = $this->appConfig->getValueString(Application::APP_ID, 'default_stt_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID;

		try {
			$transcription = $this->watsonxAPIService->transcribeFile($userId, $inputFile, false, $model);
			return ['output' => $transcription];
		} catch (Exception $e) {
			$this->logger->warning('Watsonx\'s Whisper transcription failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new RuntimeException('Watsonx\'s Whisper transcription failed with: ' . $e->getMessage());
		}
	}
}
