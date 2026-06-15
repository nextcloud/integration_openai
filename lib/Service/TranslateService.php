<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use OCA\OpenAi\AppInfo\Application;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class TranslateService {
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
					'required' => ['translation'],
					'additionalProperties' => false,
				],
			],
		],
	];

	public function __construct(
		private OpenAiSettingsService $openAiSettingsService,
		private LoggerInterface $logger,
		private OpenAiAPIService $openAiAPIService,
		private ChunkService $chunkService,
		private ICacheFactory $cacheFactory,
	) {
	}

	/**
	 * @return array<array{code: string, name: string}>
	 */
	public static function getStaticLanguages(): array {
		return array_map(static function (array $language): array {
			return [
				'code' => $language[0],
				'name' => $language[1],
			];
		}, Application::LANGUAGE_CODES_AND_ENDONYMS);
	}

	/**
	 * @return array<string, string>
	 */
	public static function getCoreLanguagesByCode(): array {
		return array_column(Application::LANGUAGE_CODES_AND_ENDONYMS, 1, 0);
	}

	public function translate(
		string $inputText, string $sourceLanguageCode, string $targetLanguageCode, string $model, ?int $maxTokens,
		?string $userId, ?callable $reportProgress = null, bool $preferStreaming = false, ?callable $reportOutput = null,
	): string {
		$chunks = $this->chunkService->chunkSplitPrompt($inputText, true, $maxTokens);
		$translation = '';
		$increase = 1.0 / (float)count($chunks);
		$progress = 0.0;

		$coreLanguages = self::getCoreLanguagesByCode();

		$fromLanguage = $sourceLanguageCode;
		$toLanguage = $coreLanguages[$targetLanguageCode] ?? $targetLanguageCode;

		if ($sourceLanguageCode !== 'detect_language') {
			$fromLanguage = $coreLanguages[$sourceLanguageCode] ?? $sourceLanguageCode;
			$promptStart = 'Translate the following text from ' . $fromLanguage . ' to ' . $toLanguage . ': ';
		} else {
			$promptStart = 'Translate the following text to ' . $toLanguage . ': ';
		}

		$cache = $this->cacheFactory->createDistributed('integration_openai');
		foreach ($chunks as $chunk) {
			$progress += $increase;
			$cacheKey = $sourceLanguageCode . '/' . $targetLanguageCode . '/' . md5($chunk);

			if ($cached = $cache->get($cacheKey)) {
				$this->logger->debug('Using cached translation', ['cached' => $cached, 'cacheKey' => $cacheKey]);
				$translation .= $cached;
				if ($reportProgress !== null) {
					$reportProgress($progress);
				}
				if ($preferStreaming && $reportOutput !== null) {
					$reportOutput($translation);
				}
				continue;
			}
			$prompt = $promptStart . PHP_EOL . PHP_EOL . $chunk;

			if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
				$completionsObj = $this->openAiAPIService->createChatCompletion(
					$userId, $model, $prompt, TranslateService::SYSTEM_PROMPT, null, 1, $maxTokens, TranslateService::JSON_RESPONSE_FORMAT
				);
				$completions = $completionsObj['messages'];
			} else {
				$completions = $this->openAiAPIService->createCompletion(
					$userId, $prompt . PHP_EOL . TranslateService::SYSTEM_PROMPT . PHP_EOL . PHP_EOL, 1, $model, $maxTokens
				);
			}

			if ($reportProgress !== null) {
				$reportProgress($progress);
			}

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
			$translation .= $decodedCompletion['translation'];
			if ($preferStreaming && $reportOutput !== null) {
				$reportOutput($translation);
			}
			$cache->set($cacheKey, $decodedCompletion['translation']);
		}
		return $translation;
	}
}
