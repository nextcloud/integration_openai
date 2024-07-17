<?php
/**
 * Nextcloud - OpenAI
 *
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2022
 */

namespace OCA\OpenAi\AppInfo;

use OCA\OpenAi\Capabilities;
use OCA\OpenAi\TaskProcessing\ContextWriteProvider;
use OCA\OpenAi\TaskProcessing\HeadlineProvider;
use OCA\OpenAi\TaskProcessing\ReformulateProvider;
use OCA\OpenAi\TaskProcessing\STTProvider;
use OCA\OpenAi\TaskProcessing\SummaryProvider;
use OCA\OpenAi\TaskProcessing\TextToImageProvider;
use OCA\OpenAi\TaskProcessing\TextToTextChatProvider;
use OCA\OpenAi\TaskProcessing\TextToTextProvider;
use OCA\OpenAi\TaskProcessing\TopicsProvider;
use OCA\OpenAi\Translation\TranslationProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;

use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;

class Application extends App implements IBootstrap {
	public const APP_ID = 'integration_openai';

	public const OPENAI_API_BASE_URL = 'https://api.openai.com';
	public const OPENAI_DEFAULT_REQUEST_TIMEOUT = 60 * 4;
	public const USER_AGENT = 'Nextcloud OpenAI/LocalAI integration';

	public const DEFAULT_COMPLETION_MODEL_ID = 'gpt-3.5-turbo';
	public const DEFAULT_TRANSCRIPTION_MODEL_ID = 'whisper-1';
	public const DEFAULT_IMAGE_SIZE = '1024x1024';
	public const MAX_GENERATION_IDLE_TIME = 60 * 60 * 24 * 10;
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

	public const PROMPT_TYPE_IMAGE = 0;
	public const PROMPT_TYPE_TEXT = 1;
	public const MAX_PROMPT_PER_TYPE_PER_USER = 5;

	private IConfig $config;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->config = $container->query(IConfig::class);
	}

	public function register(IRegistrationContext $context): void {
		if ($this->config->getAppValue(Application::APP_ID, 'translation_provider_enabled', '1') === '1') {
			$context->registerTranslationProvider(TranslationProvider::class);
		}
		if ($this->config->getAppValue(Application::APP_ID, 'stt_provider_enabled', '1') === '1') {
			$context->registerTaskProcessingProvider(STTProvider::class);
		}

		if ($this->config->getAppValue(Application::APP_ID, 'llm_provider_enabled', '1') === '1') {
			$context->registerTaskProcessingProvider(TextToTextProvider::class);
			$context->registerTaskProcessingProvider(TextToTextChatProvider::class);
			$context->registerTaskProcessingProvider(SummaryProvider::class);
			$context->registerTaskProcessingProvider(HeadlineProvider::class);
			$context->registerTaskProcessingProvider(TopicsProvider::class);
			$context->registerTaskProcessingProvider(ContextWriteProvider::class);
			$context->registerTaskProcessingProvider(ReformulateProvider::class);
		}
		if ($this->config->getAppValue(Application::APP_ID, 't2i_provider_enabled', '1') === '1') {
			$context->registerTaskProcessingProvider(TextToImageProvider::class);
		}

		$context->registerCapability(Capabilities::class);
	}

	public function boot(IBootContext $context): void {
	}
}
