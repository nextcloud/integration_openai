<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AudioToAudioChatProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IL10N $l,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-audio2audio:chat';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		if (class_exists('OCP\\TaskProcessing\\TaskTypes\\AudioToAudioChat')) {
			return \OCP\TaskProcessing\TaskTypes\AudioToAudioChat::ID;
		}
		return AudioToAudioChatTaskType::ID;
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
		$isUsingOpenAi = $this->openAiAPIService->isUsingOpenAi();
		$ois = [
			'llm_model' => new ShapeDescriptor(
				$this->l->t('Completion model'),
				$this->l->t('The model used to generate the completion'),
				EShapeType::Enum
			),
			'voice' => new ShapeDescriptor(
				$this->l->t('Output voice'),
				$this->l->t('The voice used to generate speech'),
				EShapeType::Enum
			),
		];
		if (!$isUsingOpenAi) {
			$ois['tts_model'] = new ShapeDescriptor(
				$this->l->t('Text-to-speech model'),
				$this->l->t('The model used to generate the speech'),
				EShapeType::Enum
			);
			$ois['speed'] = new ShapeDescriptor(
				$this->l->t('Speed'),
				$this->openAiAPIService->isUsingOpenAi()
					? $this->l->t('Speech speed modifier (Valid values: 0.25-4)')
					: $this->l->t('Speech speed modifier'),
				EShapeType::Number
			);
		}
		return $ois;
	}

	public function getOptionalInputShapeEnumValues(): array {
		$isUsingOpenAi = $this->openAiAPIService->isUsingOpenAi();
		$voices = json_decode($this->appConfig->getValueString(Application::APP_ID, 'tts_voices')) ?: Application::DEFAULT_SPEECH_VOICES;
		$models = $this->openAiAPIService->getModelEnumValues($this->userId);
		$enumValues = [
			'voice' => array_map(function ($v) { return new ShapeEnumValue($v, $v); }, $voices),
			'llm_model' => $models,
		];
		if (!$isUsingOpenAi) {
			$enumValues['tts_model'] = $models;
		}
		return $enumValues;
	}

	public function getOptionalInputShapeDefaults(): array {
		$isUsingOpenAi = $this->openAiAPIService->isUsingOpenAi();
		$adminVoice = $this->appConfig->getValueString(Application::APP_ID, 'default_speech_voice') ?: Application::DEFAULT_SPEECH_VOICE;
		$adminLlmModel = $isUsingOpenAi
			? 'gpt-4o-audio-preview'
			: $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id');
		$defaults = [
			'voice' => $adminVoice,
			'llm_model' => $adminLlmModel,
		];
		if (!$isUsingOpenAi) {
			$adminTtsModel = $this->appConfig->getValueString(Application::APP_ID, 'default_speech_model_id') ?: Application::DEFAULT_SPEECH_MODEL_ID;
			$defaults['tts_model'] = $adminTtsModel;
			$defaults['speed'] = 1;
		}
		return $defaults;
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [
			'input_transcript' => new ShapeDescriptor(
				$this->l->t('Input transcript'),
				$this->l->t('Transcription of the input audio'),
				EShapeType::Text,
			),
		];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		if (!isset($input['input']) || !$input['input'] instanceof File || !$input['input']->isReadable()) {
			throw new RuntimeException('Invalid input audio file in the "input" field. A readable file is expected.');
		}
		$inputFile = $input['input'];

		if (!isset($input['system_prompt']) || !is_string($input['system_prompt'])) {
			throw new RuntimeException('Invalid system_prompt');
		}
		$systemPrompt = $input['system_prompt'];

		if (!isset($input['history']) || !is_array($input['history'])) {
			throw new RuntimeException('Invalid chat history, array expected');
		}
		$history = $input['history'];

		if (isset($input['tts_model']) && is_string($input['tts_model'])) {
			$ttsModel = $input['tts_model'];
		} else {
			$ttsModel = $this->appConfig->getValueString(Application::APP_ID, 'default_speech_model_id', Application::DEFAULT_SPEECH_MODEL_ID) ?: Application::DEFAULT_SPEECH_MODEL_ID;
		}

		if (isset($input['llm_model']) && is_string($input['llm_model'])) {
			$llmModel = $input['llm_model'];
		} else {
			$isUsingOpenAi = $this->openAiAPIService->isUsingOpenAi();
			$llmModel = $isUsingOpenAi
				? 'gpt-4o-audio-preview'
				: ($this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID);
		}


		if (isset($input['voice']) && is_string($input['voice'])) {
			$outputVoice = $input['voice'];
		} else {
			$outputVoice = $this->appConfig->getValueString(Application::APP_ID, 'default_speech_voice', Application::DEFAULT_SPEECH_VOICE) ?: Application::DEFAULT_SPEECH_VOICE;
		}

		$speed = 1;
		if (isset($input['speed']) && is_numeric($input['speed'])) {
			$speed = $input['speed'];
			if ($this->openAiAPIService->isUsingOpenAi()) {
				if ($speed > 4) {
					$speed = 4;
				} elseif ($speed < 0.25) {
					$speed = 0.25;
				}
			}
		}

		$sttModel = $this->appConfig->getValueString(Application::APP_ID, 'default_stt_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID;

		/////////////// Using the chat API if connected to OpenAI
		if ($this->openAiAPIService->isUsingOpenAi()) {
			$b64Audio = base64_encode($inputFile->getContent());
			$extraParams = [
				'modalities' => ['text', 'audio'],
				'audio' => ['voice' => $outputVoice, 'format' => 'mp3'],
			];
			$completion = $this->openAiAPIService->createChatCompletion(
				$userId, $llmModel, null, $systemPrompt, $history, 1, 1000,
				$extraParams, null, null, $b64Audio,
			);
			$message = array_pop($completion['audio_messages']);
			$result = [
				'output' => base64_decode($message['audio']['data']),
				'output_transcript' => $message['audio']['transcript'],
			];

			// we still want the input transcription
			try {
				$inputTranscription = $this->openAiAPIService->transcribeFile($userId, $inputFile, false, $sttModel);
				$result['input_transcript'] = $inputTranscription;
			} catch (Exception $e) {
				$this->logger->warning('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage(), ['exception' => $e]);
			}

			return $result;
		}

		//////////////// 3 steps: STT -> LLM -> TTS
		// speech to text
		try {
			$inputTranscription = $this->openAiAPIService->transcribeFile($userId, $inputFile, false, $sttModel);
		} catch (Exception $e) {
			$this->logger->warning('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new RuntimeException('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage());
		}

		// free prompt
		try {
			$completion = $this->openAiAPIService->createChatCompletion($userId, $llmModel, $inputTranscription, $systemPrompt, $history, 1, 1000);
			$completion = $completion['messages'];
		} catch (Exception $e) {
			throw new RuntimeException('OpenAI/LocalAI request failed: ' . $e->getMessage());
		}
		if (count($completion) === 0) {
			throw new RuntimeException('No completion in OpenAI/LocalAI response.');
		}
		$llmResult = array_pop($completion);

		// text to speech
		try {
			$apiResponse = $this->openAiAPIService->requestSpeechCreation($userId, $llmResult, $ttsModel, $outputVoice, $speed);

			if (!isset($apiResponse['body'])) {
				$this->logger->warning('OpenAI/LocalAI\'s text to speech generation failed: no speech returned');
				throw new RuntimeException('OpenAI/LocalAI\'s text to speech generation failed: no speech returned');
			}
			return [
				'output' => $apiResponse['body'],
				'output_transcript' => $llmResult,
				'input_transcript' => $inputTranscription,
			];
		} catch (\Exception $e) {
			$this->logger->warning('OpenAI/LocalAI\'s text to speech generation failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new RuntimeException('OpenAI/LocalAI\'s text to speech generation failed with: ' . $e->getMessage());
		}
	}
}
