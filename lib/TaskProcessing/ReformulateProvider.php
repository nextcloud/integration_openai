<?php

declare(strict_types=1);

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use RuntimeException;

class ReformulateProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IConfig $config,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-reformulate';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return ReformulateTaskType::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime();
	}

	public function getOptionalInputShape(): array {
		return [
			'temperature' => new ShapeDescriptor(
				$this->l->t('Temperature'),
				$this->l->t('What sampling temperature to use, between 0 and 2. Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic.'),
				EShapeType::Number
			),
			'max_tokens' => new ShapeDescriptor(
				$this->l->t('Maximum tokens'),
				$this->l->t('The maximum number of tokens that can be generated in the completion.'),
				EShapeType::Number
			),
		];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$startTime = time();
		$adminModel = $this->config->getAppValue(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new RuntimeException('Invalid prompt');
		}
		$prompt = $input['input'];
		$prompt = 'Reformulate the following text. Use the same language as the original text.  Output only the reformulation. Here is the text:' . "\n\n" . $prompt . "\n\n" . 'Here is your reformulation in the same language:';

		$temperature = null;
		if (isset($input['temperature'])
			&& (is_float($input['temperature']) || is_int($input['temperature']))) {
			$temperature = $input['temperature'];
		}

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		$extraParams = $temperature === null
			? null
			: ['temperature' => $temperature];

		try {
			if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
				$completion = $this->openAiAPIService->createChatCompletion($this->userId, $prompt, 1, $adminModel, $maxTokens, $extraParams);
			} else {
				$completion = $this->openAiAPIService->createCompletion($this->userId, $prompt, 1, $adminModel, $maxTokens, $extraParams);
			}
		} catch (Exception $e) {
			throw new RuntimeException('OpenAI/LocalAI request failed: ' . $e->getMessage());
		}
		if (count($completion) > 0) {
			$endTime = time();
			$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
			return ['output' => array_pop($completion)];
		}

		throw new RuntimeException('No result in OpenAI/LocalAI response.');
	}
}
