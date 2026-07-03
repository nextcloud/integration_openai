<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\AudioToTextSubtitles;
use Psr\Log\LoggerInterface;

class AudioToTextSubtitlesProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-audio2text-subtitles';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName(Application::SERVICE_TYPE_STT);
	}

	public function getTaskTypeId(): string {
		return AudioToTextSubtitles::ID;
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
			'language' => new ShapeDescriptor(
				$this->l->t('Language'),
				$this->l->t('The language of the audio file'),
				EShapeType::Enum
			),
			'format' => new ShapeDescriptor(
				$this->l->t('File format'),
				$this->l->t('The format of the subtitles file'),
				EShapeType::Enum
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		$languageEnumValues = array_map(static function (array $language) {
			return new ShapeEnumValue($language[1], $language[0]);
		}, Application::LANGUAGE_CODES_AND_ENDONYMS);
		$detectLanguageEnumValue = new ShapeEnumValue($this->l->t('Detect language'), 'detect_language');
		$defaultLanguageEnumValue = new ShapeEnumValue($this->l->t('Default'), 'default');
		return [
			'language' => array_merge([$detectLanguageEnumValue, $defaultLanguageEnumValue], $languageEnumValues),
			'format' => [
				new ShapeEnumValue($this->l->t('SubRip Text'), 'srt'),
				new ShapeEnumValue($this->l->t('WebVTT'), 'vtt'),
			],
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [
			'language' => 'default',
			'format' => Application::DEFAULT_SUBTITLE_FORMAT,
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
		if (!isset($input['input']) || !$input['input'] instanceof File || !$input['input']->isReadable()) {
			throw new ProcessingException('Invalid input file');
		}

		$fileSize = intval($input['input']->getSize());
		// Maximum file size for OpenAI is 25MB. (https://developers.openai.com/api/docs/guides/speech-to-text)
		if ($fileSize > 25 * 1000 * 1000) {
			throw new UserFacingProcessingException(
				'Filesize of input is too large. Max is 25MB',
				0,
				null,
				$this->l->t('The input file size is too large. A maximum of 25MB is allowed.'),
			);
		}

		$fileType = $input['input']->getMimeType();
		if (!str_starts_with($fileType, 'audio/')) {
			throw new UserFacingProcessingException(
				'Invalid input file type ' . $fileType,
				0,
				null,
				$this->l->t('The input file type is invalid. Only audio files are allowed.'),
			);
		}
		if ($this->openAiAPIService->isUsingOpenAi()) {
			$validFileTypes = [
				'audio/mp3',
				'audio/mp4',
				'audio/mpeg',
				'audio/mpga',
				'audio/m4a',
				'audio/wav',
				'audio/webm',
			];
			if (!in_array($fileType, $validFileTypes)) {
				throw new ProcessingException('Invalid input file type for OpenAI ' . $fileType);
			}
		}

		$inputFile = $input['input'];
		$format = $input['format'];
		$language = $input['language'] ?? 'default';
		if (!is_string($language)) {
			throw new ProcessingException('Invalid language');
		}

		$model = $this->appConfig->getValueString(Application::APP_ID, 'default_stt_model_id', Application::DEFAULT_MODEL_ID, lazy: true) ?: Application::DEFAULT_MODEL_ID;

		try {
			$transcription = $this->openAiAPIService->transcribeFile($userId, $inputFile, false, $model, $language, $format);
			return ['output' => $transcription];
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->warning('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new ProcessingException('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage());
		}
	}
}
