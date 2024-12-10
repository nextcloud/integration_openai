<?php

declare(strict_types=1);

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;
use RuntimeException;

class TextToTextChatWithToolsProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IAppConfig $appConfig,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2text:chatwithtools';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToTextChatWithTools::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime();
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
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

	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$startTime = time();
		$adminModel = $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new RuntimeException('Invalid input');
		}
		$userPrompt = $input['input'];

		if (!isset($input['system_prompt']) || !is_string($input['system_prompt'])) {
			throw new RuntimeException('Invalid system_prompt');
		}
		$systemPrompt = $input['system_prompt'];

		if (!isset($input['tool_message']) || !is_string($input['tool_message'])) {
			throw new RuntimeException('Invalid tool_message');
		}
		// TODO find a solution to allow passing no tool message
		// OpenAI is rejecting the request if this param is set when there was no tool call done before
		// and we are requiring this task input param
		$toolMessage = $input['tool_message'];

		if (!isset($input['tools']) || !is_string($input['tools'])) {
			throw new RuntimeException('Invalid tools');
		}
		$tools = json_decode($input['tools'], true);
		if (!is_array($tools)) {
			throw new RuntimeException('Invalid JSON tools');
		}

		if (!isset($input['history']) || !is_array($input['history'])) {
			throw new RuntimeException('Invalid history');
		}
		$history = $input['history'];

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		try {
			$completion = $this->openAiAPIService->createChatCompletion(
				$userId, $adminModel, $userPrompt, $systemPrompt, $history, 1, $maxTokens, null, $toolMessage, $tools
			);
		} catch (Exception $e) {
			throw new RuntimeException('OpenAI/LocalAI request failed: ' . $e->getMessage());
		}
		if (count($completion['messages']) > 0 || count($completion['tool_calls']) > 0) {
			$endTime = time();
			$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
			return [
				'output' => array_pop($completion['messages']) ?? '',
				'tool_calls' => array_pop($completion['tool_calls']) ?? '',
			];
		}

		throw new RuntimeException('No result in OpenAI/LocalAI response.');
	}
}
