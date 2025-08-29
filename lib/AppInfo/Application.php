<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\AppInfo;

use OCA\OpenAi\Capabilities;
use OCA\OpenAi\Notification\Notifier;
use OCA\OpenAi\OldProcessing\Translation\TranslationProvider as OldTranslationProvider;
use OCA\OpenAi\TaskProcessing\AudioToAudioChatProvider;
use OCA\OpenAi\TaskProcessing\AudioToTextProvider;
use OCA\OpenAi\TaskProcessing\ChangeToneProvider;
use OCA\OpenAi\TaskProcessing\ChangeToneTaskType;
use OCA\OpenAi\TaskProcessing\ContextWriteProvider;
use OCA\OpenAi\TaskProcessing\EmojiProvider;
use OCA\OpenAi\TaskProcessing\HeadlineProvider;
use OCA\OpenAi\TaskProcessing\ReformulateProvider;
use OCA\OpenAi\TaskProcessing\SummaryProvider;
use OCA\OpenAi\TaskProcessing\TextToImageProvider;
use OCA\OpenAi\TaskProcessing\TextToSpeechProvider;
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
	public const DEFAULT_SPEECH_MODEL_ID = 'tts-1-hd';
	public const DEFAULT_SPEECH_VOICE = 'alloy';
	public const DEFAULT_SPEECH_VOICES = [
		'alloy', 'ash', 'ballad', 'coral', 'echo', 'fable',
		'onyx', 'nova', 'sage', 'shimmer', 'verse'
	];
	public const DEFAULT_DEFAULT_IMAGE_SIZE = '1024x1024';
	public const MAX_GENERATION_IDLE_TIME = 60 * 60 * 24 * 10;
	public const DEFAULT_CHUNK_SIZE = 10000;
	public const MIN_CHUNK_SIZE = 500;
	public const DEFAULT_MAX_NUM_OF_TOKENS = 1000;
	public const DEFAULT_QUOTA_PERIOD = 30;
	public const DEFAULT_QUOTA_CONFIG = ['length' => self::DEFAULT_QUOTA_PERIOD, 'unit' => 'day', 'day' => 1];

	public const DEFAULT_OPENAI_TEXT_GENERATION_TIME = 10; // seconds
	public const DEFAULT_LOCALAI_TEXT_GENERATION_TIME = 60; // seconds
	public const DEFAULT_OPENAI_IMAGE_GENERATION_TIME = 20; // seconds
	public const DEFAULT_LOCALAI_IMAGE_GENERATION_TIME = 90; // seconds
	public const EXPECTED_RUNTIME_LOWPASS_FACTOR = 0.1;

	public const QUOTA_TYPE_TEXT = 0;
	public const QUOTA_TYPE_IMAGE = 1;
	public const QUOTA_TYPE_TRANSCRIPTION = 2;
	public const QUOTA_TYPE_SPEECH = 3;

	public const DEFAULT_QUOTAS = [
		self::QUOTA_TYPE_TEXT => 0, // 0 = unlimited
		self::QUOTA_TYPE_IMAGE => 0, // 0 = unlimited
		self::QUOTA_TYPE_TRANSCRIPTION => 0, // 0 = unlimited
		self::QUOTA_TYPE_SPEECH => 0, // 0 = unlimited

	];

	public const MODELS_CACHE_KEY = 'models';
	public const MODELS_CACHE_TTL = 60 * 30;

	public const AUDIO_TO_TEXT_LANGUAGES = [['en', 'English'], ['zh', '中文'], ['de', 'Deutsch'], ['es', 'Español'], ['ru', 'Русский'], ['ko', '한국어'], ['fr', 'Français'], ['ja', '日本語'], ['pt', 'Português'], ['tr', 'Türkçe'], ['pl', 'Polski'], ['ca', 'Català'], ['nl', 'Nederlands'], ['ar', 'العربية'], ['sv', 'Svenska'], ['it', 'Italiano'], ['id', 'Bahasa Indonesia'], ['hi', 'हिन्दी'], ['fi', 'Suomi'], ['vi', 'Tiếng Việt'], ['he', 'עברית'], ['uk', 'Українська'], ['el', 'Ελληνικά'], ['ms', 'Bahasa Melayu'], ['cs', 'Česky'], ['ro', 'Română'], ['da', 'Dansk'], ['hu', 'Magyar'], ['ta', 'தமிழ்'], ['no', 'Norsk (bokmål / riksmål)'], ['th', 'ไทย / Phasa Thai'], ['ur', 'اردو'], ['hr', 'Hrvatski'], ['bg', 'Български'], ['lt', 'Lietuvių'], ['la', 'Latina'], ['mi', 'Māori'], ['ml', 'മലയാളം'], ['cy', 'Cymraeg'], ['sk', 'Slovenčina'], ['te', 'తెలుగు'], ['fa', 'فارسی'], ['lv', 'Latviešu'], ['bn', 'বাংলা'], ['sr', 'Српски'], ['az', 'Azərbaycanca / آذربايجان'], ['sl', 'Slovenščina'], ['kn', 'ಕನ್ನಡ'], ['et', 'Eesti'], ['mk', 'Македонски'], ['br', 'Brezhoneg'], ['eu', 'Euskara'], ['is', 'Íslenska'], ['hy', 'Հայերեն'], ['ne', 'नेपाली'], ['mn', 'Монгол'], ['bs', 'Bosanski'], ['kk', 'Қазақша'], ['sq', 'Shqip'], ['sw', 'Kiswahili'], ['gl', 'Galego'], ['mr', 'मराठी'], ['pa', 'ਪੰਜਾਬੀ / पंजाबी / پنجابي'], ['si', 'සිංහල'], ['km', 'ភាសាខ្មែរ'], ['sn', 'chiShona'], ['yo', 'Yorùbá'], ['so', 'Soomaaliga'], ['af', 'Afrikaans'], ['oc', 'Occitan'], ['ka', 'ქართული'], ['be', 'Беларуская'], ['tg', 'Тоҷикӣ'], ['sd', 'सिनधि'], ['gu', 'ગુજરાતી'], ['am', 'አማርኛ'], ['yi', 'ייִדיש'], ['lo', 'ລາວ / Pha xa lao'], ['uz', 'Ўзбек'], ['fo', 'Føroyskt'], ['ht', 'Krèyol ayisyen'], ['ps', 'پښتو'], ['tk', 'Туркмен / تركمن'], ['nn', 'Norsk (nynorsk)'], ['mt', 'bil-Malti'], ['sa', 'संस्कृतम्'], ['lb', 'Lëtzebuergesch'], ['my', 'Myanmasa'], ['bo', 'བོད་ཡིག / Bod skad'], ['tl', 'Tagalog'], ['mg', 'Malagasy'], ['as', 'অসমীয়া'], ['tt', 'Tatarça'], ['haw', 'ʻŌlelo Hawaiʻi'], ['ln', 'Lingála'], ['ha', 'هَوُسَ'], ['ba', 'Башҡорт'], ['jw', 'ꦧꦱꦗꦮ'], ['su', 'Basa Sunda'], ['yue', '粤语']];

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

		$serviceUrl = $this->appConfig->getValueString(Application::APP_ID, 'url');
		$isUsingOpenAI = $serviceUrl === '' || $serviceUrl === Application::OPENAI_API_BASE_URL;

		if ($this->appConfig->getValueString(Application::APP_ID, 'llm_provider_enabled', '1') === '1') {
			$context->registerTaskProcessingProvider(TextToTextProvider::class);
			$context->registerTaskProcessingProvider(TextToTextChatProvider::class);
			$context->registerTaskProcessingProvider(SummaryProvider::class);
			$context->registerTaskProcessingProvider(HeadlineProvider::class);
			$context->registerTaskProcessingProvider(TopicsProvider::class);
			$context->registerTaskProcessingProvider(ContextWriteProvider::class);
			$context->registerTaskProcessingProvider(ReformulateProvider::class);
			$context->registerTaskProcessingProvider(EmojiProvider::class);
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
			if ($isUsingOpenAI || $this->appConfig->getValueString(Application::APP_ID, 'analyze_image_provider_enabled') === '1') {
				if (!class_exists('OCP\\TaskProcessing\\TaskTypes\\AnalyzeImages')) {
					$context->registerTaskProcessingTaskType(\OCA\OpenAi\TaskProcessing\AnalyzeImagesTaskType::class);
				}
				$context->registerTaskProcessingProvider(\OCA\OpenAi\TaskProcessing\AnalyzeImagesProvider::class);
			}
		}
		if (!class_exists('OCP\\TaskProcessing\\TaskTypes\\TextToSpeech')) {
			$context->registerTaskProcessingTaskType(\OCA\OpenAi\TaskProcessing\TextToSpeechTaskType::class);
		}
		$context->registerTaskProcessingProvider(TextToSpeechProvider::class);
		if ($this->appConfig->getValueString(Application::APP_ID, 't2i_provider_enabled', '1') === '1') {
			$context->registerTaskProcessingProvider(TextToImageProvider::class);
		}

		// only register audio chat stuff if we're using OpenAI or stt+llm+tts are enabled
		if (
			$isUsingOpenAI
			|| (
				$this->appConfig->getValueString(Application::APP_ID, 'stt_provider_enabled', '1') === '1'
				&& $this->appConfig->getValueString(Application::APP_ID, 'llm_provider_enabled', '1') === '1'
				&& $this->appConfig->getValueString(Application::APP_ID, 'tts_provider_enabled', '1') === '1'
			)
		) {
			if (class_exists('OCP\\TaskProcessing\\TaskTypes\\AudioToAudioChat')) {
				$context->registerTaskProcessingProvider(AudioToAudioChatProvider::class);
			}
		}

		$context->registerCapability(Capabilities::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
