<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\AppInfo;

use OCA\OpenAi\Capabilities;
use OCA\OpenAi\OldProcessing\Translation\TranslationProvider as OldTranslationProvider;
use OCA\OpenAi\TaskProcessing\AudioToTextProvider;
use OCA\OpenAi\TaskProcessing\ChangeToneProvider;
use OCA\OpenAi\TaskProcessing\ChangeToneTaskType;
use OCA\OpenAi\TaskProcessing\ContextWriteProvider;
use OCA\OpenAi\TaskProcessing\HeadlineProvider;
use OCA\OpenAi\TaskProcessing\ReformulateProvider;
use OCA\OpenAi\TaskProcessing\SummaryProvider;
use OCA\OpenAi\TaskProcessing\TextToImageProvider;
use OCA\OpenAi\TaskProcessing\TextToTextChatProvider;
use OCA\OpenAi\TaskProcessing\TextToTextProvider;
use OCA\OpenAi\TaskProcessing\TopicsProvider;
use OCA\OpenAi\TaskProcessing\TranslateProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;

use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;

class Application extends App implements IBootstrap {
	public const APP_ID = 'integration_openai';

	public const OPENAI_API_BASE_URL = 'https://api.openai.com/v1';
	public const OPENAI_DEFAULT_REQUEST_TIMEOUT = 60 * 4;
	public const USER_AGENT = 'Nextcloud OpenAI/LocalAI integration';

	public const DEFAULT_MODEL_ID = 'Default';
	public const DEFAULT_COMPLETION_MODEL_ID = 'gpt-3.5-turbo';
	public const DEFAULT_IMAGE_MODEL_ID = 'dall-e-2';
	public const DEFAULT_TRANSCRIPTION_MODEL_ID = 'whisper-1';
	public const DEFAULT_DEFAULT_IMAGE_SIZE = '512x512';
	public const MAX_GENERATION_IDLE_TIME = 60 * 60 * 24 * 10;
	public const DEFAULT_CHUNK_SIZE = 10000;
	public const MIN_CHUNK_SIZE = 500;
	public const DEFAULT_MAX_NUM_OF_TOKENS = 1000;
	public const DEFAULT_QUOTA_PERIOD = 30;

	public const DEFAULT_OPENAI_TEXT_GENERATION_TIME = 10; // seconds
	public const DEFAULT_LOCALAI_TEXT_GENERATION_TIME = 60; // seconds
	public const DEFAULT_OPENAI_IMAGE_GENERATION_TIME = 20; // seconds
	public const DEFAULT_LOCALAI_IMAGE_GENERATION_TIME = 90; // seconds
	public const EXPECTED_RUNTIME_LOWPASS_FACTOR = 0.1;

	public const QUOTA_TYPE_TEXT = 0;
	public const QUOTA_TYPE_IMAGE = 1;
	public const QUOTA_TYPE_TRANSCRIPTION = 2;

	public const DEFAULT_QUOTAS = [
		self::QUOTA_TYPE_TEXT => 0, // 0 = unlimited
		self::QUOTA_TYPE_IMAGE => 0, // 0 = unlimited
		self::QUOTA_TYPE_TRANSCRIPTION => 0, // 0 = unlimited

	];

	public const MODELS_CACHE_KEY = 'models';
	public const MODELS_CACHE_TTL = 60 * 30;

	private IAppConfig $appConfig;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->appConfig = $container->get(IAppConfig::class);
	}

	public function register(IRegistrationContext $context): void {
		// deprecated APIs
		if ($this->appConfig->getValueString(Application::APP_ID, 'translation_provider_enabled', '1') === '1') {
			$context->registerTranslationProvider(OldTranslationProvider::class);
		}

		// Task processing
		if ($this->appConfig->getValueString(Application::APP_ID, 'translation_provider_enabled', '1') === '1') {
			$context->registerTaskProcessingProvider(TranslateProvider::class);
		}
		if ($this->appConfig->getValueString(Application::APP_ID, 'stt_provider_enabled', '1') === '1') {
			$context->registerTaskProcessingProvider(AudioToTextProvider::class);
		}

		if ($this->appConfig->getValueString(Application::APP_ID, 'llm_provider_enabled', '1') === '1') {
			$context->registerTaskProcessingProvider(TextToTextProvider::class);
			$context->registerTaskProcessingProvider(TextToTextChatProvider::class);
			$context->registerTaskProcessingProvider(SummaryProvider::class);
			$context->registerTaskProcessingProvider(HeadlineProvider::class);
			$context->registerTaskProcessingProvider(TopicsProvider::class);
			$context->registerTaskProcessingProvider(ContextWriteProvider::class);
			$context->registerTaskProcessingProvider(ReformulateProvider::class);
			if (!class_exists('OCP\\TaskProcessing\\TaskTypes\\TextToTextChangeTone')) {
				$context->registerTaskProcessingTaskType(ChangeToneTaskType::class);
			}
			$context->registerTaskProcessingProvider(ChangeToneProvider::class);
			if (class_exists('OCP\\TaskProcessing\\TaskTypes\\TextToTextChatWithTools')) {
				$context->registerTaskProcessingProvider(\OCA\OpenAi\TaskProcessing\TextToTextChatWithToolsProvider::class);
			}
			if (class_exists('OCP\\TaskProcessing\\TaskTypes\\TextToTextProofread')) {
				$context->registerTaskProcessingProvider(\OCA\OpenAi\TaskProcessing\ProofreadProvider::class);
			}
		}
		if ($this->appConfig->getValueString(Application::APP_ID, 't2i_provider_enabled', '1') === '1') {
			$context->registerTaskProcessingProvider(TextToImageProvider::class);
		}

		$context->registerCapability(Capabilities::class);
	}

	public function boot(IBootContext $context): void {
	}
}
