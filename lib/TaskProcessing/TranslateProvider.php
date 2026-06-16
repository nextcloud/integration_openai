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
use OCA\OpenAi\Service\TranslateService;
use OCP\ICacheFactory;
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
use Psr\Log\LoggerInterface;

class TranslateProvider implements IProvider, ISynchronousOptionsAwareProvider {

	public const SYSTEM_PROMPT = 'You are a translations expert that ONLY outputs a valid JSON with the translated text in the following format: { "translation": "<translated text>" } .';
	public const JSON_RESPONSE_FORMAT = [
		'response_format' => [
			'type' => 'json_schema',
			'json_schema' => [
				'name' => 'TranslationResponse',
				'description' => 'A JSON object containing the translated text',
				'strict' => true,
				'schema' => [
					'type' => 'object',
					'properties' => [
						'translation' => [
							'type' => 'string',
							'description' => 'The translated text',
						],
					],
					'required' => [ 'translation' ],
					'additionalProperties' => false,
				],
			],
		],
	];

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
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

		$chunks = $this->chunkService->chunkSplitPrompt($inputText, true, $maxTokens);
		$result = '';
		$increase = 1.0 / (float)count($chunks);
		$progress = 0.0;
		try {
			$coreLanguages = TranslateService::getCoreLanguagesByCode();

			$fromLanguage = $input['origin_language'];
			$toLanguage = $coreLanguages[$input['target_language']] ?? $input['target_language'];

			if ($input['origin_language'] !== 'detect_language') {
				$fromLanguage = $coreLanguages[$input['origin_language']] ?? $input['origin_language'];
				$promptStart = 'Translate the following text from ' . $fromLanguage . ' to ' . $toLanguage . ': ';
			} else {
				$promptStart = 'Translate the following text to ' . $toLanguage . ': ';
			}

			foreach ($chunks as $chunk) {
				$progress += $increase;
				$cacheKey = ($input['origin_language'] ?? '') . '/' . $input['target_language'] . '/' . md5($chunk);

				$cache = $this->cacheFactory->createDistributed('integration_openai');
				if ($cached = $cache->get($cacheKey)) {
					$this->logger->debug('Using cached translation', ['cached' => $cached, 'cacheKey' => $cacheKey]);
					$result .= $cached;
					$reportProgress($progress);
					if ($preferStreaming) {
						$reportOutput(['output' => $result]);
					}
					continue;
				}
				$prompt = $promptStart . PHP_EOL . PHP_EOL . $chunk;

				if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
					$completionsObj = $this->openAiAPIService->createChatCompletion(
						$userId, $model, $prompt, self::SYSTEM_PROMPT, null, 1, $maxTokens, self::JSON_RESPONSE_FORMAT
					);
					$completions = $completionsObj['messages'];
				} else {
					$completions = $this->openAiAPIService->createCompletion(
						$userId, $prompt . PHP_EOL . self::SYSTEM_PROMPT . PHP_EOL . PHP_EOL, 1, $model, $maxTokens
					);
				}

				$reportProgress($progress);

				if (count($completions) === 0) {
					$this->logger->error('Empty translation response received for chunk');
					continue;
				}

				$completion = array_pop($completions);
				$decodedCompletion = json_decode($completion, true);
				if (
					!isset($decodedCompletion['translation'])
					|| !is_string($decodedCompletion['translation'])
					|| empty($decodedCompletion['translation'])
				) {
					$this->logger->error('Invalid translation response received for chunk', ['response' => $completion]);
					continue;
				}
				$result .= $decodedCompletion['translation'];
				if ($preferStreaming) {
					$reportOutput(['output' => $result]);
				}
				$cache->set($cacheKey, $decodedCompletion['translation']);
				continue;
			}

			$endTime = time();
			$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);

			if (empty(trim($result))) {
				throw new ProcessingException("Empty translation result from {$fromLanguage} to {$toLanguage}");
			}
			return ['output' => trim($result)];

		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (Exception $e) {
			throw new ProcessingException(
				"Failed to translate from {$fromLanguage} to {$toLanguage}: {$e->getMessage()}",
				$e->getCode(),
				$e,
			);
		}
	}
}
