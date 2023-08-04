<?php

declare(strict_types=1);
namespace OCA\OpenAi\TextProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\TextProcessing\IProvider;

class ReformulateProvider implements IProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	public function getName(): string {
		return $this->openAiAPIService->isUsingOpenAi()
			? $this->l10n->t('OpenAI integration')
			: $this->l10n->t('LocalAI integration');
	}

	public function process(string $prompt): string {
		$adminModel = $this->config->getAppValue(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;
		$prompt = 'Reformulate the following text:' . "\n\n" . $prompt;
		$completion = $this->openAiAPIService->createChatCompletion(null, $prompt, 1, $adminModel, 100, false);
		if (isset($completion['choices']) && is_array($completion['choices']) && count($completion['choices']) > 0) {
			$choice = $completion['choices'][0];
			if (isset($choice['message'], $choice['message']['content'])) {
				return $choice['message']['content'];
			}
		}
		throw new \Exception('No result in OpenAI/LocalAI response. ' . ($completion['error'] ?? ''));
	}

	public function getTaskType(): string {
		return ReformulateTaskType::class;
	}
}
