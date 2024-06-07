<?php

declare(strict_types=1);

namespace OCA\OpenAi\TextProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\IConfig;
use OCP\TextProcessing\HeadlineTaskType;
use OCP\TextProcessing\IProviderWithExpectedRuntime;
use OCP\TextProcessing\IProviderWithUserId;
use RuntimeException;

/**
 * @template-implements IProviderWithExpectedRuntime<HeadlineTaskType>
 * @template-implements IProviderWithUserId<HeadlineTaskType>
 */
class HeadlineProvider implements IProviderWithExpectedRuntime, IProviderWithUserId {
	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IConfig $config,
		private ?string $userId,
		private OpenAiSettingsService $openAiSettingsService,
	) {
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function process(string $prompt): string {
		$startTime = time();
		$adminModel = $this->config->getAppValue(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;
		$prompt = 'Give me the headline of the following text in its original language. Output only the headline.' . "\n\n" . $prompt;
		// Max tokens are limited later to max tokens specified in the admin settings so here we just request PHP_INT_MAX
		try {
			if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
				$completion = $this->openAiAPIService->createChatCompletion($this->userId, $prompt, 1, $adminModel, PHP_INT_MAX);
			} else {
				$completion = $this->openAiAPIService->createCompletion($this->userId, $prompt, 1, $adminModel, PHP_INT_MAX);
			}
		} catch (Exception $e) {
			throw new RunTimeException('OpenAI/LocalAI request failed: ' . $e->getMessage());
		}
		if (count($completion) > 0) {
			$endTime = time();
			$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
			return array_pop($completion);
		}

		throw new RunTimeException('No result in OpenAI/LocalAI response. ');
	}

	public function getTaskType(): string {
		return HeadlineTaskType::class;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime();
	}

	public function setUserId(?string $userId): void {
		$this->userId = $userId;
	}
}
