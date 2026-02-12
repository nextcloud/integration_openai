<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\TranslationService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;

class TranslateProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IAppConfig $appConfig,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private IFactory $l10nFactory,
		private TranslationService $translationService,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-translate';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToTextTranslate::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime();
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
		return [
			'max_tokens' => new ShapeDescriptor(
				$this->l->t('Maximum output words'),
				$this->l->t('The maximum number of words/tokens that can be generated in the completion.'),
				EShapeType::Number
			),
			'model' => new ShapeDescriptor(
				$this->l->t('Model'),
				$this->l->t('The model used to generate the completion'),
				EShapeType::Enum
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [
			'model' => $this->openAiAPIService->getModelEnumValues($this->userId),
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		$adminModel = $this->openAiAPIService->isUsingOpenAi()
			? ($this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID, lazy: true) ?: Application::DEFAULT_MODEL_ID)
			: $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', lazy: true);
		return [
			'max_tokens' => $this->openAiSettingsService->getMaxTokens(),
			'model' => $adminModel,
		];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$startTime = time();
		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID, lazy: true) ?: Application::DEFAULT_MODEL_ID;
		}

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new ProcessingException('Invalid input text');
		}
		if (empty($input['input'])) {
			throw new UserFacingProcessingException($this->l->t('Input text cannot be empty'));
		}
		$inputText = $input['input'];

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		try {
			$translation = $this->translationService->translate(
				$inputText, $input['origin_language'], $input['target_language'], $model, $maxTokens, $userId, $reportProgress,
			);

			$endTime = time();
			$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);

			if (empty($translation)) {
				throw new ProcessingException("Empty translation result from {$input['origin_language']} to {$input['target_language']}");
			}
			return ['output' => $translation];

		} catch (Exception $e) {
			throw new ProcessingException(
				"Failed to translate from {$input['origin_language']} to {$input['target_language']}: {$e->getMessage()}",
				$e->getCode(),
				$e,
			);
		}
	}
}
