<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\OldProcessing\Translation;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\L10N\IFactory;
use OCP\Translation\IDetectLanguageProvider;
use OCP\Translation\ITranslationProvider;
use OCP\Translation\LanguageTuple;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TranslationProvider implements ITranslationProvider, IDetectLanguageProvider {
	public function __construct(
		private ICacheFactory $cacheFactory,
		private IFactory $l10nFactory,
		private OpenAiAPIService $openAiAPIService,
		private LoggerInterface $logger,
		private IConfig $config,
		private ?string $userId,
		private OpenAiSettingsService $openAiSettingsService,
	) {
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getAvailableLanguages(): array {
		$cache = $this->cacheFactory->createDistributed('integration_openai');
		if ($cached = $cache->get('languages')) {
			return array_map(function ($entry) {
				return $entry instanceof LanguageTuple ? $entry : LanguageTuple::fromArray($entry);
			}, $cached);
		}

		$coreL = $this->l10nFactory->getLanguages();
		$languages = array_merge($coreL['commonLanguages'], $coreL['otherLanguages']);

		$availableLanguages = [];
		foreach ($languages as $sourceLanguage) {
			foreach ($languages as $targetLanguage) {
				if ($targetLanguage['code'] === $sourceLanguage['code']) {
					continue;
				}

				$availableLanguages[] = new LanguageTuple(
					$sourceLanguage['code'],
					$sourceLanguage['name'],
					$targetLanguage['code'],
					$targetLanguage['name']
				);
			}
		}

		$cache->set('languages', $availableLanguages, 3600);
		return $availableLanguages;
	}

	public function detectLanguage(string $text): ?string {
		$prompt = 'What language is this (answer with the language name only, in English): ' . $text;
		$adminModel = $this->config->getAppValue(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;
		try {
			if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
				$completion = $this->openAiAPIService->createChatCompletion($this->userId, $adminModel, $prompt, null, null, 1, 100);
				$completion = $completion['messages'];
			} else {
				$completion = $this->openAiAPIService->createCompletion($this->userId, $prompt, 1, $adminModel, 100);
			}
		} catch (Exception $e) {
			throw new RuntimeException('Failed to detect language for input', 0, $e);
		}

		if (count($completion) > 0) {
			return array_pop($completion);
		}
		return null;
	}

	private function getCoreLanguagesByCode(): array {
		$coreL = $this->l10nFactory->getLanguages();
		$coreLanguages = array_reduce(array_merge($coreL['commonLanguages'], $coreL['otherLanguages']), function ($carry, $val) {
			$carry[$val['code']] = $val['name'];
			return $carry;
		});
		return $coreLanguages;
	}

	public function translate(?string $fromLanguage, string $toLanguage, string $text): string {
		$cacheKey = ($fromLanguage ?? '') . '/' . $toLanguage . '/' . md5($text);

		$cache = $this->cacheFactory->createDistributed('integration_openai');
		if ($cached = $cache->get($cacheKey)) {
			return $cached;
		}

		try {
			$coreLanguages = $this->getCoreLanguagesByCode();

			$toLanguage = $coreLanguages[$toLanguage];
			if ($fromLanguage !== null) {
				$this->logger->debug('OpenAI translation FROM[' . $fromLanguage . '] TO[' . $toLanguage . ']', ['app' => Application::APP_ID]);
				$fromLanguage = $coreLanguages[$fromLanguage] ?? $fromLanguage;
				$prompt = 'Translate from ' . $fromLanguage . ' to ' . $toLanguage . ': ' . $text;
			} else {
				$this->logger->debug('OpenAI translation TO[' . $toLanguage . ']', ['app' => Application::APP_ID]);
				$prompt = 'Translate to ' . $toLanguage . ': ' . $text;
			}
			$adminModel = $this->config->getAppValue(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;

			if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
				$completion = $this->openAiAPIService->createChatCompletion($this->userId, $adminModel, $prompt, null, null, 1, PHP_INT_MAX);
				$completion = $completion['messages'];
			} else {
				$completion = $this->openAiAPIService->createCompletion($this->userId, $prompt, 1, $adminModel, 4000);
			}

			if (count($completion) > 0) {
				return array_pop($completion);
			}

		} catch (Exception $e) {
			throw new RuntimeException("Failed translate from {$fromLanguage} to {$toLanguage}", 0, $e);
		}
		throw new RuntimeException("Failed translate from {$fromLanguage} to {$toLanguage}");
	}
}
