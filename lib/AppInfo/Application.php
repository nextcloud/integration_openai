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
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\IConfig;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_openai';
	public const DEFAULT_COMPLETION_MODEL_ID = 'gpt-3.5-turbo';
	public const DEFAULT_IMAGE_SIZE = '1024x1024';
	public const MAX_GENERATION_IDLE_TIME = 60 * 60 * 24 * 10;

	private IConfig $config;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->config = $container->query(IConfig::class);
	}

	public function register(IRegistrationContext $context): void {
		$apiKey = $this->config->getAppValue(self::APP_ID, 'api_key');
		if ($apiKey !== '') {
			$context->registerReferenceProvider(ChatGptReferenceProvider::class);
			$context->registerReferenceProvider(ImageReferenceProvider::class);
			$context->registerReferenceProvider(WhisperReferenceProvider::class);
			$context->registerEventListener(RenderReferenceEvent::class, OpenAiReferenceListener::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}

