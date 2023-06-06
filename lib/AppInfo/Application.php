<?php
/**
 * Nextcloud - OpenAI
 *
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2022
 */

namespace OCA\OpenAi\AppInfo;

use OCA\OpenAi\Listener\OpenAiReferenceListener;
use OCA\OpenAi\Reference\ChatGptReferenceProvider;
use OCA\OpenAi\Reference\ImageReferenceProvider;
use OCA\OpenAi\Reference\WhisperReferenceProvider;
use OCA\OpenAi\SpeechToText\STTProvider;
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

	public const DEFAULT_COMPLETION_MODEL_ID = 'gpt-3.5-turbo';
	public const DEFAULT_TRANSCRIPTION_MODEL_ID = 'whisper-1';
	public const DEFAULT_IMAGE_SIZE = '1024x1024';
	public const MAX_GENERATION_IDLE_TIME = 60 * 60 * 24 * 10;

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

		if (version_compare($this->config->getSystemValueString('version', '0.0.0'), '27.0.0', '>=')) {
			if ($this->config->getAppValue(Application::APP_ID, 'stt_provider_enabled', '1') === '1') {
				$context->registerSpeechToTextProvider(STTProvider::class);
			}
		}
	}

	public function boot(IBootContext $context): void {
	}
}

