<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\AppInfo;

use OCA\Watsonx\Capabilities;
use OCA\Watsonx\TaskProcessing\ChangeToneProvider;
use OCA\Watsonx\TaskProcessing\ChangeToneTaskType;
use OCA\Watsonx\TaskProcessing\ContextWriteProvider;
use OCA\Watsonx\TaskProcessing\HeadlineProvider;
use OCA\Watsonx\TaskProcessing\ReformulateProvider;
use OCA\Watsonx\TaskProcessing\SummaryProvider;
use OCA\Watsonx\TaskProcessing\TextToTextChatProvider;
use OCA\Watsonx\TaskProcessing\TextToTextProvider;
use OCA\Watsonx\TaskProcessing\TopicsProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;

use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;

class Application extends App implements IBootstrap {
	public const APP_ID = 'integration_watsonx';

	public const WATSONX_API_BASE_URL = 'https://us-south.ml.cloud.ibm.com/ml/v1';
	public const WATSONX_DEFAULT_REQUEST_TIMEOUT = 60 * 4;
	public const USER_AGENT = 'Nextcloud IBM watsonx AI integration';

	public const DEFAULT_MODEL_ID = 'Default';
	public const DEFAULT_COMPLETION_MODEL_ID = 'gpt-3.5-turbo';
	public const MAX_GENERATION_IDLE_TIME = 60 * 60 * 24 * 10;
	public const DEFAULT_CHUNK_SIZE = 10000;
	public const MIN_CHUNK_SIZE = 500;
	public const DEFAULT_MAX_NUM_OF_TOKENS = 1000;
	public const DEFAULT_QUOTA_PERIOD = 30;

	public const DEFAULT_WATSONX_TEXT_GENERATION_TIME = 10; // seconds
	public const LOCAL_WATSONX_TEXT_GENERATION_TIME = 60; // seconds
	public const EXPECTED_RUNTIME_LOWPASS_FACTOR = 0.1;

	public const QUOTA_TYPE_TEXT = 0;

	public const DEFAULT_QUOTAS = [
		self::QUOTA_TYPE_TEXT => 0, // 0 = unlimited

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
		// Task processing
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
				$context->registerTaskProcessingProvider(\OCA\Watsonx\TaskProcessing\TextToTextChatWithToolsProvider::class);
			}
			if (class_exists('OCP\\TaskProcessing\\TaskTypes\\TextToTextProofread')) {
				$context->registerTaskProcessingProvider(\OCA\Watsonx\TaskProcessing\ProofreadProvider::class);
			}
		}

		$context->registerCapability(Capabilities::class);
	}

	public function boot(IBootContext $context): void {
	}
}
