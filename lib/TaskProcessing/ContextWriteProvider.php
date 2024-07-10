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
use OCP\TaskProcessing\TaskTypes\ContextWrite;
use RuntimeException;

class ContextWriteProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IConfig $config,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-contextwrite';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return ContextWrite::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime();
	}

	public function getOptionalInputShape(): array {
		return [
			'max_tokens' => new ShapeDescriptor(
				$this->l->t('Maximum output words'),
				$this->l->t('The maximum number of words/tokens that can be generated in the completion.'),
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

		if (
			!isset($input['style_input']) || !is_string($input['style_input'])
				|| !isset($input['source_input']) || !is_string($input['source_input'])
		) {
			throw new RuntimeException('Invalid inputs');
		}
		$writingStyle = $input['style_input'];
		$sourceMaterial = $input['source_input'];

		$prompt = 'You\'re a professional copywriter tasked with copying an instructed or demonstrated *WRITING STYLE*'
			. ' and writing a text on the provided *SOURCE MATERIAL*.'
			. " \n*WRITING STYLE*:\n$writingStyle\n\n*SOURCE MATERIAL*:\n\n$sourceMaterial\n\n"
			. 'Now write a text in the same style detailed or demonstrated under *WRITING STYLE* using the *SOURCE MATERIAL*'
			. ' as source of facts and instruction on what to write about.'
			. ' Do not invent any facts or events yourself.'
			. ' Also, use the *WRITING STYLE* as a guide for how to write the text ONLY and not as a source of facts or events.'
			. ' Detect the language used in the *SOURCE_MATERIAL*. Make sure to use the same language in your response. Do not mention the language explicitly.';

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		try {
			if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
				$completion = $this->openAiAPIService->createChatCompletion($this->userId, $adminModel, $prompt, null, null, 1, $maxTokens);
			} else {
				$completion = $this->openAiAPIService->createCompletion($this->userId, $prompt, 1, $adminModel, $maxTokens);
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
