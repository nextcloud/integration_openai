<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\AppInfo;

use OCA\OpenAi\Capabilities;
use OCA\OpenAi\Listener\TaskProcessingProviderListener;
use OCA\OpenAi\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\TaskProcessing\Events\GetTaskProcessingProvidersEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'integration_openai';

	public const OPENAI_API_BASE_URL = 'https://api.openai.com/v1';
	public const OPENAI_DEFAULT_REQUEST_TIMEOUT = 60 * 4;
	public const USER_AGENT = 'Nextcloud OpenAI/LocalAI integration';

	public const DEFAULT_MODEL_ID = 'Default';
	public const DEFAULT_COMPLETION_MODEL_ID = 'gpt-4.1-mini';
	public const DEFAULT_IMAGE_MODEL_ID = 'gpt-image-1-mini';
	public const DEFAULT_TRANSCRIPTION_MODEL_ID = 'whisper-1';
	public const DEFAULT_SPEECH_MODEL_ID = 'tts-1-hd';
	public const DEFAULT_SPEECH_VOICE = 'alloy';
	public const DEFAULT_SPEECH_VOICES = [
		'alloy', 'ash', 'ballad', 'coral', 'echo', 'fable',
		'onyx', 'nova', 'sage', 'shimmer', 'verse'
	];
	public const DEFAULT_SUBTITLE_FORMAT = 'srt';
	public const SUPPORTED_SUBTITLE_FORMATS = [
		'srt', 'vtt'
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
	public const QUOTA_RULES_CACHE_PREFIX = 'quota_rules';
	public const MODELS_CACHE_TTL = 60 * 30;

	public const LANGUAGE_CODES_AND_ENDONYMS = [['en', 'English'], ['zh', '中文'], ['de', 'Deutsch'], ['es', 'Español'], ['ru', 'Русский'], ['ko', '한국어'], ['fr', 'Français'], ['ja', '日本語'], ['pt', 'Português'], ['tr', 'Türkçe'], ['pl', 'Polski'], ['ca', 'Català'], ['nl', 'Nederlands'], ['ar', 'العربية'], ['sv', 'Svenska'], ['it', 'Italiano'], ['id', 'Bahasa Indonesia'], ['hi', 'हिन्दी'], ['fi', 'Suomi'], ['vi', 'Tiếng Việt'], ['he', 'עברית'], ['uk', 'Українська'], ['el', 'Ελληνικά'], ['ms', 'Bahasa Melayu'], ['cs', 'Česky'], ['ro', 'Română'], ['da', 'Dansk'], ['hu', 'Magyar'], ['ta', 'தமிழ்'], ['no', 'Norsk (bokmål / riksmål)'], ['th', 'ไทย / Phasa Thai'], ['ur', 'اردو'], ['hr', 'Hrvatski'], ['bg', 'Български'], ['lt', 'Lietuvių'], ['la', 'Latina'], ['mi', 'Māori'], ['ml', 'മലയാളം'], ['cy', 'Cymraeg'], ['sk', 'Slovenčina'], ['te', 'తెలుగు'], ['fa', 'فارسی'], ['lv', 'Latviešu'], ['bn', 'বাংলা'], ['sr', 'Српски'], ['az', 'Azərbaycanca / آذربايجان'], ['sl', 'Slovenščina'], ['kn', 'ಕನ್ನಡ'], ['et', 'Eesti'], ['mk', 'Македонски'], ['br', 'Brezhoneg'], ['eu', 'Euskara'], ['is', 'Íslenska'], ['hy', 'Հայերեն'], ['ne', 'नेपाली'], ['mn', 'Монгол'], ['bs', 'Bosanski'], ['kk', 'Қазақша'], ['sq', 'Shqip'], ['sw', 'Kiswahili'], ['gl', 'Galego'], ['mr', 'मराठी'], ['pa', 'ਪੰਜਾਬੀ / पंजाबी / پنجابي'], ['si', 'සිංහල'], ['km', 'ភាសាខ្មែរ'], ['sn', 'chiShona'], ['yo', 'Yorùbá'], ['so', 'Soomaaliga'], ['af', 'Afrikaans'], ['oc', 'Occitan'], ['ka', 'ქართული'], ['be', 'Беларуская'], ['tg', 'Тоҷикӣ'], ['sd', 'सिनधि'], ['gu', 'ગુજરાતી'], ['am', 'አማርኛ'], ['yi', 'ייִדיש'], ['lo', 'ລາວ / Pha xa lao'], ['uz', 'Ўзбек'], ['fo', 'Føroyskt'], ['ht', 'Krèyol ayisyen'], ['ps', 'پښتو'], ['tk', 'Туркмен / تركمن'], ['nn', 'Norsk (nynorsk)'], ['mt', 'bil-Malti'], ['sa', 'संस्कृतम्'], ['lb', 'Lëtzebuergesch'], ['my', 'Myanmasa'], ['bo', 'བོད་ཡིག / Bod skad'], ['tl', 'Tagalog'], ['mg', 'Malagasy'], ['as', 'অসমীয়া'], ['tt', 'Tatarça'], ['haw', 'ʻŌlelo Hawaiʻi'], ['ln', 'Lingála'], ['ha', 'هَوُسَ'], ['ba', 'Башҡорт'], ['jw', 'ꦧꦱꦗꦮ'], ['su', 'Basa Sunda'], ['yue', '粤语']];

	/**
	 * The modalities the admin can select models for. Each selected model of a
	 * modality is exposed as one task processing provider per task type of
	 * that modality.
	 */
	public const MODALITY_TEXT = 'text';
	public const MODALITY_IMAGE = 'image';
	public const MODALITY_STT = 'stt';
	public const MODALITY_TTS = 'tts';
	/** App config key holding the JSON list of connected services */
	public const SERVICES_CONFIG_KEY = 'services';

	/** Sent to and accepted from the frontend in place of a stored secret */
	public const SECRET_PLACEHOLDER = '**********';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// The task processing providers of this app depend on the admin's
		// service and model selection, so they cannot be registered as
		// classes. They are built per (service, model, task type) instead.
		$context->registerEventListener(GetTaskProcessingProvidersEvent::class, TaskProcessingProviderListener::class);

		$context->registerCapability(Capabilities::class);
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
		$vendorDir = __DIR__ . '/../../vendor';
		\spl_autoload_register(function (string $class) use ($vendorDir) {
			$prefixes = [
				'OCA\\OpenAi\\Vendor\\lsolesen\\pel\\' => $vendorDir . '/fileeye/pel/src/',
				'OCA\\OpenAi\\Vendor\\RtfHtmlPhp\\' => $vendorDir . '/henck/rtf-to-html/src/',
				'OCA\\OpenAi\\Vendor\\Smalot\\PdfParser\\' => $vendorDir . '/smalot/pdfparser/src/Smalot/PdfParser/',
			];
			foreach ($prefixes as $prefix => $baseDir) {
				if (!str_starts_with($class, $prefix)) {
					continue;
				}
				$file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
				if (is_file($file)) {
					include_once $file;
				}
				return;
			}
		});
		// Load getID3 library for adding audio metadata
		require_once($vendorDir . '/james-heinrich/getid3/getid3/getid3.php');
	}
}
