<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use OCP\ICacheFactory;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

class TranslationService {
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
		private OpenAiSettingsService $openAiSettingsService,
		private LoggerInterface $logger,
		private OpenAiAPIService $openAiAPIService,
		private ChunkService $chunkService,
		private ICacheFactory $cacheFactory,
		private IFactory $l10nFactory,
	) {
	}

	private function getCoreLanguagesByCode(): array {
		$coreL = $this->l10nFactory->getLanguages();
		$coreLanguages = array_reduce(array_merge($coreL['commonLanguages'], $coreL['otherLanguages']), function ($carry, $val) {
			$carry[$val['code']] = $val['name'];
			return $carry;
		});
		return $coreLanguages;
	}

	public function translate(
		string $inputText, string $sourceLanguageCode, string $targetLanguageCode, string $model, ?int $maxTokens,
		?string $userId, ?callable $reportProgress = null,
	): string {
		$chunks = $this->chunkService->chunkSplitPrompt($inputText, true, $maxTokens);
		$translation = '';
		$increase = 1.0 / (float)count($chunks);
		$progress = 0.0;
		$coreLanguages = $this->getCoreLanguagesByCode();

		$toLanguage = $coreLanguages[$targetLanguageCode] ?? $targetLanguageCode;

		if ($sourceLanguageCode !== 'detect_language') {
			$fromLanguage = $coreLanguages[$sourceLanguageCode] ?? $sourceLanguageCode;
			$promptStart = 'Translate the following text from ' . $fromLanguage . ' to ' . $toLanguage . ': ';
		} else {
			$promptStart = 'Translate the following text to ' . $toLanguage . ': ';
		}

		foreach ($chunks as $chunk) {
			$progress += $increase;
			$cacheKey = $sourceLanguageCode . '/' . $targetLanguageCode . '/' . md5($chunk);

			$cache = $this->cacheFactory->createDistributed('integration_openai');
			if ($cached = $cache->get($cacheKey)) {
				$this->logger->debug('Using cached translation', ['cached' => $cached, 'cacheKey' => $cacheKey]);
				$translation .= $cached;
				if ($reportProgress !== null) {
					$reportProgress($progress);
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
			$cache->set($cacheKey, $decodedCompletion['translation']);
			continue;
		}
		return $translation;
	}
}
