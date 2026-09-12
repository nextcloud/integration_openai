<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServiceConfig;
use OCA\OpenAi\Service\TranslateService;
use OCA\OpenAi\Service\WatermarkingService;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ISynchronousOptionsAwareProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\SynchronousProviderOptions;
use OCP\TaskProcessing\TaskTypes\AudioToAudioTranslate;
use Psr\Log\LoggerInterface;

/**
 * Translates spoken audio into spoken audio of another language by chaining
 * transcription, translation and speech generation on one service.
 *
 * Registered once per selected speech-to-text model; the models for the other
 * two steps are the first text and text-to-speech models selected for the same
 * service.
 */
class AudioToAudioTranslateProvider implements IProvider, ISynchronousOptionsAwareProvider {
	use ProviderIdentity;

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private TranslateService $translateService,
		private WatermarkingService $watermarkingService,
		private LoggerInterface $logger,
		private IFactory $l10nFactory,
		private IL10N $l,
		private IUserManager $userManager,
		private ServiceConfig $service,
		/** The speech-to-text model this provider is registered for */
		private string $model,
		/** The text model used to translate the transcription */
		private string $textModel,
		/** The text-to-speech model used to read out the translation */
		private string $ttsModel,
	) {
	}

	public function getId(): string {
		return $this->buildProviderId('audio2audio:translate');
	}

	public function getName(): string {
		return $this->buildProviderName();
	}

	public function getTaskTypeId(): string {
		return AudioToAudioTranslate::ID;
	}

	public function getExpectedRuntime(): int {
		return 60;
	}

	public function getInputShapeEnumValues(): array {
		$languages = TranslateService::getStaticLanguages();
		$languageEnumValues = array_map(static function (array $language) {
			return new ShapeEnumValue($language['name'], $language['code']);
		}, $languages);
		$detectLanguageEnumValue = new ShapeEnumValue($this->l->t('Detect language'), 'detect_language');
		return [
			'origin_language' => array_merge([$detectLanguageEnumValue], $languageEnumValues),
			'target_language' => $languageEnumValues,
		];
	}

	public function getInputShapeDefaults(): array {
		return [
			'origin_language' => 'detect_language',
		];
	}

	public function getOptionalInputShape(): array {
		return [
			'tts_voice' => new ShapeDescriptor(
				$this->l->t('Voice'),
				$this->l->t('The voice to use'),
				EShapeType::Enum
			),
			'tts_speed' => new ShapeDescriptor(
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
			'tts_voice' => array_map(
				static fn (string $voice) => new ShapeEnumValue($voice, $voice),
				$this->service->getTtsVoices(),
			),
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [
			'tts_voice' => $this->service->getDefaultTtsVoice(),
			'tts_speed' => 1,
		];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [
			'text_input' => new ShapeDescriptor(
				$this->l->t('Audio transcription'),
				$this->l->t('The transcribed audio input'),
				EShapeType::Text,
			),
			'text_output' => new ShapeDescriptor(
				$this->l->t('Text output'),
				$this->l->t('The text translation'),
				EShapeType::Text,
			),
		];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(
		?string $userId, array $input, callable $reportProgress, SynchronousProviderOptions $options = new SynchronousProviderOptions(),
	): array {
		$includeWatermark = $options->getIncludeWatermarks();
		$reportOutput = $options->getReportIntermediateOutput();
		$preferStreaming = $options->getPreferStreaming();

		if (!isset($input['input']) || !$input['input'] instanceof File || !$input['input']->isReadable()) {
			throw new ProcessingException('Invalid input file');
		}
		$inputFile = $input['input'];

		if (!isset($input['origin_language']) || !is_string($input['origin_language'])) {
			throw new ProcessingException('Invalid origin_language input');
		}
		if (!isset($input['target_language']) || !is_string($input['target_language'])) {
			throw new ProcessingException('Invalid target_language input');
		}

		// STT
		try {
			$transcription = $this->openAiAPIService->transcribeFile($userId, $this->service, $inputFile, false, $this->model, $input['origin_language']);
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (Exception $e) {
			$this->logger->warning('Transcription failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException(
				'Transcription failed with: ' . $e->getMessage(),
				$e->getCode(),
				$e,
			);
		}
		if (empty(trim($transcription))) {
			throw new ProcessingException("Empty transcription result from {$input['origin_language']} to {$input['target_language']}");
		}
		$watermarkSuffix = '';
		if ($includeWatermark) {
			if ($userId !== null) {
				$user = $this->userManager->getExistingUser($userId);
				$lang = $this->l10nFactory->getUserLanguage($user);
				$l = $this->l10nFactory->get(Application::APP_ID, $lang);
				$watermarkSuffix = "\n\n" . $l->t('This was generated using Artificial Intelligence.');
			} else {
				$watermarkSuffix = "\n\n" . $this->l->t('This was generated using Artificial Intelligence.');
			}
		}

		$reportProgress(0.3);

		if ($preferStreaming) {
			$running = $reportOutput([
				'text_input' => $transcription . $watermarkSuffix,
			]);
			if (!$running) {
				throw new ProcessingException('OpenAI/LocalAI task cancelled');
			}
		}

		// translate
		$maxTokens = $this->service->getMaxTokens();

		try {
			$reportTranslationOutput = function (string $translationOutput) use ($reportOutput, $transcription, $watermarkSuffix) {
				$running = $reportOutput([
					'text_input' => $transcription . $watermarkSuffix,
					'text_output' => $translationOutput,
				]);
				if (!$running) {
					throw new ProcessingException('OpenAI/LocalAI task cancelled');
				}
			};
			$translatedText = $this->translateService->translate(
				$this->service,
				$transcription, $input['origin_language'], $input['target_language'],
				$this->textModel, $maxTokens, $userId, null,
				$preferStreaming, $reportTranslationOutput,
			);

			if ($preferStreaming) {
				$running = $reportOutput([
					'text_input' => $transcription . $watermarkSuffix,
					'text_output' => $translatedText . $watermarkSuffix,
				]);
				if (!$running) {
					throw new ProcessingException('OpenAI/LocalAI task cancelled');
				}
			}

			if (empty($translatedText)) {
				throw new ProcessingException("Empty translation result from {$input['origin_language']} to {$input['target_language']}");
			}
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (Exception $e) {
			throw new ProcessingException(
				"Failed to translate from {$input['origin_language']} to {$input['target_language']}: {$e->getMessage()}",
				$e->getCode(),
				$e,
			);
		}

		$reportProgress(0.6);

		// TTS
		$ttsPrompt = $translatedText . $watermarkSuffix;
		$voice = isset($input['tts_voice']) && is_string($input['tts_voice'])
			? $input['tts_voice']
			: $this->service->getDefaultTtsVoice();

		$speed = 1;
		if (isset($input['tts_speed']) && is_numeric($input['tts_speed'])) {
			$speed = $input['tts_speed'];
			if ($this->service->isUsingOpenAi()) {
				if ($speed > 4) {
					$speed = 4;
				} elseif ($speed < 0.25) {
					$speed = 0.25;
				}
			}
		}

		try {
			$apiResponse = $this->openAiAPIService->requestSpeechCreation(
				$userId, $this->service, $ttsPrompt, $this->ttsModel, $voice, $speed,
			);

			if (!isset($apiResponse['body'])) {
				$this->logger->warning('Text to speech generation failed: no speech returned');
				throw new ProcessingException('Text to speech generation failed: no speech returned');
			}
			$translatedAudio = $includeWatermark ? $this->watermarkingService->markAudio($apiResponse['body']) : $apiResponse['body'];
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (Exception $e) {
			$this->logger->warning('Text to speech generation failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException(
				'Text to speech generation failed with: ' . $e->getMessage(),
				$e->getCode(),
				$e,
			);
		}

		$reportProgress(1.0);

		// Translation
		return [
			'text_input' => $transcription . $watermarkSuffix,
			'audio_output' => $translatedAudio,
			'text_output' => $translatedText . $watermarkSuffix,
		];
	}
}
