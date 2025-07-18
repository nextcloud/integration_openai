<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\ChunkService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TranslateProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IAppConfig $appConfig,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private IFactory $l10nFactory,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private ChunkService $chunkService,
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
			? ($this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID)
			: $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id');
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

	private function getCoreLanguagesByCode(): array {
		$coreL = $this->l10nFactory->getLanguages();
		$coreLanguages = array_reduce(array_merge($coreL['commonLanguages'], $coreL['otherLanguages']), function ($carry, $val) {
			$carry[$val['code']] = $val['name'];
			return $carry;
		});
		return $coreLanguages;
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		/*
		foreach (range(1, 20) as $i) {
			$reportProgress($i / 100 * 5);
			error_log('aa ' . ($i / 100 * 5));
			sleep(1);
		}
		*/
		$startTime = time();
		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID;
		}

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new RuntimeException('Invalid input text');
		}
		$inputText = $input['input'];

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		$cacheKey = ($input['origin_language'] ?? '') . '/' . $input['target_language'] . '/' . md5($inputText);

		$cache = $this->cacheFactory->createDistributed('integration_openai');
		if ($cached = $cache->get($cacheKey)) {
			return ['output' => $cached];
		}
		$chunks = $this->chunkService->chunkSplitPrompt($inputText, true, $maxTokens);
		$result = '';
		$increase = 1.0 / (float)count($chunks);
		$progress = 0.0;
		try {
			$coreLanguages = $this->getCoreLanguagesByCode();

			$toLanguage = $coreLanguages[$input['target_language']] ?? $input['target_language'];

			if ($input['origin_language'] !== 'detect_language') {
				$fromLanguage = $coreLanguages[$input['origin_language']] ?? $input['origin_language'];
				$promptStart = 'Translate from ' . $fromLanguage . ' to ' . $toLanguage . ': ';
			} else {
				$promptStart = 'Translate to ' . $toLanguage . ': ';
			}

			foreach ($chunks as $chunk) {
				$prompt = $promptStart . $chunk;

				if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
					$completion = $this->openAiAPIService->createChatCompletion($userId, $model, $prompt, null, null, 1, $maxTokens);
					$completion = $completion['messages'];
				} else {
					$completion = $this->openAiAPIService->createCompletion($userId, $prompt, 1, $model, $maxTokens);
				}

				$progress += $increase;
				$reportProgress($progress);

				if (count($completion) > 0) {
					$result .= array_pop($completion);
					continue;
				}

				throw new RuntimeException("Failed translate from {$fromLanguage} to {$toLanguage} for chunk");
			}

			$endTime = time();
			$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
			return ['output' => $result];

		} catch (Exception $e) {
			throw new RuntimeException("Failed translate from {$fromLanguage} to {$toLanguage}", 0, $e);
		}
	}
}
