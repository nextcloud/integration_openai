<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCA\OpenAi\Service\TranslateService;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ISynchronousOptionsAwareProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\SynchronousProviderOptions;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;

class TranslateProvider implements IProvider, ISynchronousOptionsAwareProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private TranslateService $translateService,
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
		$adminModel = $this->openAiSettingsService->getAdminDefaultCompletionModelId();
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

	public function process(
		?string $userId, array $input, callable $reportProgress, SynchronousProviderOptions $options = new SynchronousProviderOptions(),
	): array {
		$reportOutput = $options->getReportIntermediateOutput();
		$preferStreaming = $options->getPreferStreaming();

		$startTime = time();
		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->openAiSettingsService->getAdminDefaultCompletionModelId();
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

		$coreLanguages = TranslateService::getCoreLanguagesByCode();
		$fromLanguage = $input['origin_language'];
		$toLanguage = $coreLanguages[$input['target_language']] ?? $input['target_language'];

		try {
			$reportTranslationOutput = function (string $translationOutput) use ($reportOutput) {
				$running = $reportOutput([
					'output' => $translationOutput,
				]);
				if (!$running) {
					throw new ProcessingException('OpenAI/LocalAI task cancelled');
				}
			};
			$translation = $this->translateService->translate(
				$inputText, $input['origin_language'] ?? '', $input['target_language'] ?? '',
				$model, $maxTokens, $userId, $reportProgress,
				$preferStreaming, $reportTranslationOutput,
			);

			$endTime = time();
			$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);

			if (empty(trim($translation))) {
				throw new ProcessingException("Empty translation result from {$fromLanguage} to {$toLanguage}");
			}
			return ['output' => trim($translation)];
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new ProcessingException(
				"Failed to translate from {$fromLanguage} to {$toLanguage}: {$e->getMessage()}",
				$e->getCode(),
				$e,
			);
		}
	}
}
