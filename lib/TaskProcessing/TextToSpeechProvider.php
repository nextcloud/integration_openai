<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServiceConfig;
use OCA\OpenAi\Service\WatermarkingService;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\ISynchronousWatermarkingProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use Psr\Log\LoggerInterface;

class TextToSpeechProvider implements ISynchronousWatermarkingProvider {
	use ProviderIdentity;

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IL10N $l,
		private LoggerInterface $logger,
		private WatermarkingService $watermarkingService,
		private ServiceConfig $service,
		private string $model,
	) {
	}

	public function getId(): string {
		return $this->buildProviderId('text2speech');
	}

	public function getName(): string {
		return $this->buildProviderName();
	}

	public function getTaskTypeId(): string {
		return \OCP\TaskProcessing\TaskTypes\TextToSpeech::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime($this->service);
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		return [
			'voice' => new ShapeDescriptor(
				$this->l->t('Voice'),
				$this->l->t('The voice to use'),
				EShapeType::Enum
			),
			'speed' => new ShapeDescriptor(
				$this->l->t('Speed'),
				$this->service->isUsingOpenAi()
					? $this->l->t('Speech speed modifier (Valid values: 0.25-4)')
					: $this->l->t('Speech speed modifier'),
				EShapeType::Number
			)
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [
			'voice' => array_map(
				static fn (string $voice) => new ShapeEnumValue($voice, $voice),
				$this->service->getTtsVoices(),
			),
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [
			'voice' => $this->service->getDefaultTtsVoice(),
			'speed' => 1,
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

	public function process(?string $userId, array $input, callable $reportProgress, bool $includeWatermark = true): array {

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new ProcessingException('Invalid prompt');
		}
		// For OpenAI the text input limit is 4096 characters (https://platform.openai.com/docs/api-reference/audio/createSpeech#audio-createspeech-input)
		$prompt = $input['input'];

		if ($includeWatermark) {
			$prompt .= "\n\n" . $this->l->t('This was generated using Artificial Intelligence.');
		}
		$model = $this->model;

		$voice = isset($input['voice']) && is_string($input['voice'])
			? $input['voice']
			: $this->service->getDefaultTtsVoice();

		$speed = 1;
		if (isset($input['speed']) && is_numeric($input['speed'])) {
			$speed = $input['speed'];
			if ($this->service->isUsingOpenAi()) {
				if ($speed > 4) {
					$speed = 4;
				} elseif ($speed < 0.25) {
					$speed = 0.25;
				}
			}
		}

		try {
			$apiResponse = $this->openAiAPIService->requestSpeechCreation($userId, $this->service, $prompt, $model, $voice, $speed);

			if (!isset($apiResponse['body'])) {
				$this->logger->warning('OpenAI/LocalAI\'s text to speech generation failed: no speech returned');
				throw new ProcessingException('OpenAI/LocalAI\'s text to speech generation failed: no speech returned');
			}
			$audio = $includeWatermark ? $this->watermarkingService->markAudio($apiResponse['body']) : $apiResponse['body'];

			return ['speech' => $audio];
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('OpenAI/LocalAI\'s text to speech generation failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException('OpenAI/LocalAI\'s text to speech generation failed with: ' . $e->getMessage());
		}
	}
}
