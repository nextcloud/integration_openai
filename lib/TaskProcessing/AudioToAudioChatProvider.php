<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServiceConfig;
use OCP\Files\File;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\AudioToAudioChat;
use Psr\Log\LoggerInterface;

/**
 * Audio chat through the integrated audio-in/audio-out chat completion
 * endpoint, which OpenAI-compatible services expose as one request.
 *
 * This is only registered for models of services that have audio attachments
 * enabled. Chaining separate speech-to-text, chat and text-to-speech providers
 * is not done here: the Assistant app registers a fallback provider for that.
 */
class AudioToAudioChatProvider implements ISynchronousProvider {
	use ProviderIdentity;

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IL10N $l,
		private LoggerInterface $logger,
		private ServiceConfig $service,
		private string $model,
		/** Used to transcribe the input, which is part of the task output */
		private string $sttModel,
		/** Only needed when the model answers with text instead of audio */
		private ?string $ttsModel,
	) {
	}

	public function getId(): string {
		return $this->buildProviderId('audio2audio:chat');
	}

	public function getName(): string {
		return $this->buildProviderName();
	}

	public function getTaskTypeId(): string {
		return AudioToAudioChat::ID;
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
				$this->l->t('Output voice'),
				$this->l->t('The voice used to generate speech'),
				EShapeType::Enum
			),
			'memories' => new ShapeDescriptor(
				$this->l->t('Memories'),
				$this->l->t('The memories to be injected into the chat session.'),
				EShapeType::ListOfTexts
			),
			'speed' => new ShapeDescriptor(
				$this->l->t('Speed'),
				$this->service->isUsingOpenAi()
					? $this->l->t('Speech speed modifier (Valid values: 0.25-4)')
					: $this->l->t('Speech speed modifier'),
				EShapeType::Number
			),
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
		return [
			'audio_id' => new ShapeDescriptor(
				$this->l->t('Remote audio ID'),
				$this->l->t('The ID of the audio response returned by the remote service'),
				EShapeType::Text
			),
			'audio_expires_at' => new ShapeDescriptor(
				$this->l->t('Remote audio expiration date'),
				$this->l->t('The remote audio response stays available in the service until this date'),
				EShapeType::Number
			),
		];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		if (!isset($input['input']) || !$input['input'] instanceof File || !$input['input']->isReadable()) {
			throw new ProcessingException('Invalid input audio file in the "input" field. A readable file is expected.');
		}
		$inputFile = $input['input'];

		if (!isset($input['system_prompt']) || !is_string($input['system_prompt'])) {
			throw new ProcessingException('Invalid system_prompt');
		}
		$systemPrompt = $input['system_prompt'];

		if (isset($input['memories']) && is_array($input['memories']) && count($input['memories'])) {
			/** @psalm-suppress InvalidArgument */
			$systemPrompt .= "\n\nYou can remember things from other conversation with the user. If they are relevant, take into account the following memories:\n" . implode("\n\n", $input['memories']) . "\n\nDo not mention these memories explicitly. You may use them as context, but do not repeat them. At most, you can mention that you remember something.";
		}

		if (!isset($input['history']) || !is_array($input['history'])) {
			throw new ProcessingException('Invalid chat history, array expected');
		}
		$history = $input['history'];

		$outputVoice = isset($input['voice']) && is_string($input['voice'])
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

		$serviceName = $this->service->getDisplayName();
		$result = [];
		$extraParams = [
			'modalities' => ['text', 'audio'],
			'audio' => ['voice' => $outputVoice, 'format' => 'mp3'],
		];
		$systemPrompt .= ' Producing text responses will break the user interface. Important: You have multimodal voice capability, and you use voice exclusively to respond.';
		$completion = $this->openAiAPIService->createChatCompletion(
			$userId, $this->service, $this->model, null, $systemPrompt, $history, 1, 1000,
			$extraParams, null, null, [$inputFile]
		);
		$message = array_pop($completion['audio_messages']);
		// TODO find a way to force the model to answer with audio when there is only text in the history
		// https://community.openai.com/t/gpt-4o-audio-preview-responds-in-text-not-audio/1006486/5
		if ($message === null) {
			// no audio, TTS the text message
			if ($this->ttsModel === null) {
				throw new ProcessingException($serviceName . ' answered with text and no text-to-speech model is configured for it');
			}
			try {
				$textResponse = array_pop($completion['messages']);
				$apiResponse = $this->openAiAPIService->requestSpeechCreation($userId, $this->service, $textResponse, $this->ttsModel, $outputVoice, $speed);
				if (!isset($apiResponse['body'])) {
					$this->logger->warning($serviceName . ' text to speech generation failed: no speech returned');
					throw new ProcessingException($serviceName . ' text to speech generation failed: no speech returned');
				}
				$output = $apiResponse['body'];
			} catch (UserFacingProcessingException $e) {
				throw $e;
			} catch (\Throwable $e) {
				$this->logger->warning($serviceName . ' text to speech generation failed with: ' . $e->getMessage(), ['exception' => $e]);
				throw new ProcessingException($serviceName . ' text to speech generation failed with: ' . $e->getMessage());
			}
		} else {
			$output = base64_decode($message['audio']['data']);
			$textResponse = $message['audio']['transcript'];
			if (isset($message['audio']['id'])) {
				$result['audio_id'] = $message['audio']['id'];
			}
			if (isset($message['audio']['expires_at'])) {
				$result['audio_expires_at'] = $message['audio']['expires_at'];
			}
		}
		$result['output'] = $output;
		$result['output_transcript'] = $textResponse;

		// the transcript of the input is part of the task output
		try {
			$result['input_transcript'] = $this->openAiAPIService->transcribeFile($userId, $this->service, $inputFile, false, $this->sttModel);
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning($serviceName . ' audio input transcription failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException($serviceName . ' audio input transcription failed with: ' . $e->getMessage());
		}

		return $result;
	}
}
