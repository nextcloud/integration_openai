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
use OCA\OpenAi\Listener\OpenAiReferenceListener;
use OCA\OpenAi\Reference\ChatGptReferenceProvider;
use OCA\OpenAi\Reference\ImageReferenceProvider;
use OCA\OpenAi\Reference\WhisperReferenceProvider;
use OCA\OpenAi\SpeechToText\STTProvider;
use OCA\OpenAi\TextProcessing\FreePromptProvider;
use OCA\OpenAi\TextProcessing\HeadlineProvider;
use OCA\OpenAi\TextProcessing\ReformulateProvider;
use OCA\OpenAi\TextProcessing\SummaryProvider;
use OCA\OpenAi\Translation\TranslationProvider;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\IConfig;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_openai';

	public const OPENAI_API_BASE_URL = 'https://api.openai.com';
	public const OPENAI_DEFAULT_REQUEST_TIMEOUT = 60 * 4;

	public const DEFAULT_COMPLETION_MODEL_ID = 'gpt-3.5-turbo';
	public const DEFAULT_TRANSCRIPTION_MODEL_ID = 'whisper-1';
	public const DEFAULT_IMAGE_SIZE = '1024x1024';
	public const MAX_GENERATION_IDLE_TIME = 60 * 60 * 24 * 10;
	public const DEFAULT_MAX_NUM_OF_TOKENS = 1000;
	public const DEFAULT_QUOTA_PERIOD = 30;
	
	public const QUOTA_TYPE_TEXT = 0;
	public const QUOTA_TYPE_IMAGE = 1;
	public const QUOTA_TYPE_TRANSCRIPTION = 2;

	
	public const QUOTA_TYPES = [
		self::QUOTA_TYPE_TEXT => 'text',
		self::QUOTA_TYPE_IMAGE => 'image',
		self::QUOTA_TYPE_TRANSCRIPTION => 'transcription',
	];
	public const DEFAULT_QUOTAS = [
		self::QUOTA_TYPE_TEXT => ['type' => self::QUOTA_TYPES[self::QUOTA_TYPE_TEXT], 'value' => 0], // 0 = unlimited
		self::QUOTA_TYPE_IMAGE => ['type' => self::QUOTA_TYPES[self::QUOTA_TYPE_IMAGE], 'value' => 0], // 0 = unlimited
		self::QUOTA_TYPE_TRANSCRIPTION => ['type' => self::QUOTA_TYPES[self::QUOTA_TYPE_TRANSCRIPTION], 'value' => 0], // 0 = unlimited

	];

	public const QUOTA_UNITS = [
		self::QUOTA_TYPE_TEXT => 'tokens',
		self::QUOTA_TYPE_IMAGE => 'images',
		self::QUOTA_TYPE_TRANSCRIPTION => 'seconds',

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
		$context->registerEventListener(RenderReferenceEvent::class, OpenAiReferenceListener::class);
		if ($this->config->getAppValue(Application::APP_ID, 'text_completion_picker_enabled', '1') === '1') {
			$context->registerReferenceProvider(ChatGptReferenceProvider::class);
		}
		if ($this->config->getAppValue(Application::APP_ID, 'image_picker_enabled', '1') === '1') {
			$context->registerReferenceProvider(ImageReferenceProvider::class);
		}
		if ($this->config->getAppValue(Application::APP_ID, 'whisper_picker_enabled', '1') === '1') {
			$context->registerReferenceProvider(WhisperReferenceProvider::class);
		}
		if ($this->config->getAppValue(Application::APP_ID, 'translation_provider_enabled', '1') === '1') {
			$context->registerTranslationProvider(TranslationProvider::class);
		}

		$context->registerTextProcessingProvider(FreePromptProvider::class);
		$context->registerTextProcessingProvider(SummaryProvider::class);
		$context->registerTextProcessingProvider(HeadlineProvider::class);
		$context->registerTextProcessingProvider(ReformulateProvider::class);

		if (version_compare($this->config->getSystemValueString('version', '0.0.0'), '27.0.0', '>=')) {
			if ($this->config->getAppValue(Application::APP_ID, 'stt_provider_enabled', '1') === '1') {
				$context->registerSpeechToTextProvider(STTProvider::class);
			}
		}
		$context->registerCapability(Capabilities::class);
	}

	public function boot(IBootContext $context): void {
	}
}

