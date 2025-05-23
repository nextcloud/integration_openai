<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToSpeech;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TextToSpeechProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IL10N $l,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2speech';
	}

	public function getName(): string {
		return $this->openAiAPIService->isUsingOpenAi()
			? $this->l->t('OpenAI\'s Text to Speech')
			: $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToSpeech::ID;
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
			'voice' => new ShapeDescriptor(
				$this->l->t('Voice'),
				$this->l->t('The voice to use'),
				EShapeType::Enum
			),
			'model' => new ShapeDescriptor(
				$this->l->t('Model'),
				$this->l->t('The model used to generate the speech'),
				EShapeType::Enum
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		$voices = json_decode($this->appConfig->getValueString(Application::APP_ID, 'tts_voices')) ?: Application::DEFAULT_SPEECH_VOICES;
		return [
			'voice' => array_map(function ($v) { return new ShapeEnumValue($v, $v); }, $voices),
			'model' => $this->openAiAPIService->getModelEnumValues($this->userId),
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		$adminVoice = $this->appConfig->getValueString(Application::APP_ID, 'default_speech_voice') ?: Application::DEFAULT_SPEECH_VOICE;
		$adminModel = $this->appConfig->getValueString(Application::APP_ID, 'default_speech_model_id') ?: Application::DEFAULT_SPEECH_MODEL_ID;
		return [
			'voice' => $adminVoice,
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

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new RuntimeException('Invalid prompt');
		}
		$prompt = $input['input'];

		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->appConfig->getValueString(Application::APP_ID, 'default_speech_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_SPEECH_MODEL_ID;
		}


		if (isset($input['voice']) && is_string($input['voice'])) {
			$voice = $input['voice'];
		} else {
			$voice = $this->appConfig->getValueString(Application::APP_ID, 'default_speech_voice', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_SPEECH_VOICE;
		}

		try {
			$apiResponse = $this->openAiAPIService->requestSpeechCreation($userId, $prompt, $model, $voice);

			if (!isset($apiResponse['body'])) {
				$this->logger->warning('OpenAI/LocalAI\'s text to speech generation failed: no speech returned');
				throw new RuntimeException('OpenAI/LocalAI\'s text to speech generation failed: no speech returned');
			}
			return ['speech' => $apiResponse['body']];
		} catch (\Exception $e) {
			$this->logger->warning('OpenAI/LocalAI\'s text to image generation failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new RuntimeException('OpenAI/LocalAI\'s text to image generation failed with: ' . $e->getMessage());
		}
	}
}
