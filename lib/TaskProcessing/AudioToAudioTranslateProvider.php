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
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\TranslationService;
use OCA\OpenAi\Service\WatermarkingService;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\ISynchronousWatermarkingProvider;
use OCP\TaskProcessing\ShapeEnumValue;
use Psr\Log\LoggerInterface;

class AudioToAudioTranslateProvider implements ISynchronousWatermarkingProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private TranslationService $translationService,
		private OpenAiSettingsService $openAiSettingsService,
		private WatermarkingService $watermarkingService,
		private LoggerInterface $logger,
		private IFactory $l10nFactory,
		private IL10N $l,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-audio2audio:translate';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName(Application::SERVICE_TYPE_STT);
	}

	public function getTaskTypeId(): string {
		return AudioToAudioTranslateTaskType::ID;
	}

	public function getExpectedRuntime(): int {
		return 60;
	}

	public function getInputShapeEnumValues(): array {
		$coreL = $this->l10nFactory->getLanguages();
		$languages = array_merge($coreL['commonLanguages'], $coreL['otherLanguages']);
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

	public function process(?string $userId, array $input, callable $reportProgress, bool $includeWatermark = true): array {
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
		$sttModel = $this->appConfig->getValueString(Application::APP_ID, 'default_stt_model_id', Application::DEFAULT_MODEL_ID, lazy: true) ?: Application::DEFAULT_MODEL_ID;
		try {
			$transcription = $this->openAiAPIService->transcribeFile($userId, $inputFile, false, $sttModel, $input['origin_language']);
		} catch (Exception $e) {
			$this->logger->warning('Transcription failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException(
				'Transcription failed with: ' . $e->getMessage(),
				$e->getCode(),
				$e,
			);
		}

		$reportProgress(0.3);

		// translate
		$completionModel = $this->openAiAPIService->isUsingOpenAi()
			? ($this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID, lazy: true) ?: Application::DEFAULT_MODEL_ID)
			: $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', lazy: true);
		$maxTokens = $this->openAiSettingsService->getMaxTokens();

		try {
			$translatedText = $this->translationService->translate(
				$transcription, $input['origin_language'], $input['target_language'], $completionModel, $maxTokens, $userId,
			);

			if (empty($translatedText)) {
				throw new ProcessingException("Empty translation result from {$input['origin_language']} to {$input['target_language']}");
			}
		} catch (Exception $e) {
			throw new ProcessingException(
				"Failed to translate from {$input['origin_language']} to {$input['target_language']}: {$e->getMessage()}",
				$e->getCode(),
				$e,
			);
		}

		$reportProgress(0.6);

		// TTS
		$ttsPrompt = $translatedText;
		if ($includeWatermark) {
			if ($userId !== null) {
				$user = $this->userManager->getExistingUser($userId);
				$lang = $this->l10nFactory->getUserLanguage($user);
				$l = $this->l10nFactory->get(Application::APP_ID, $lang);
				$ttsPrompt .= "\n\n" . $l->t('This was generated using Artificial Intelligence.');
			} else {
				$ttsPrompt .= "\n\n" . $this->l->t('This was generated using Artificial Intelligence.');
			}
		}
		$ttsModel = $this->appConfig->getValueString(Application::APP_ID, 'default_speech_model_id', Application::DEFAULT_SPEECH_MODEL_ID, lazy: true) ?: Application::DEFAULT_SPEECH_MODEL_ID;
		$voice = $this->appConfig->getValueString(Application::APP_ID, 'default_speech_voice', Application::DEFAULT_SPEECH_VOICE, lazy: true) ?: Application::DEFAULT_SPEECH_VOICE;
		$speed = 1;
		try {
			$apiResponse = $this->openAiAPIService->requestSpeechCreation($userId, $ttsPrompt, $ttsModel, $voice, $speed);

			if (!isset($apiResponse['body'])) {
				$this->logger->warning('Text to speech generation failed: no speech returned');
				throw new ProcessingException('Text to speech generation failed: no speech returned');
			}
			$translatedAudio = $includeWatermark ? $this->watermarkingService->markAudio($apiResponse['body']) : $apiResponse['body'];
		} catch (\Exception $e) {
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
			'audio_output' => $translatedAudio,
			'text_output' => $translatedText,
		];
	}
}
