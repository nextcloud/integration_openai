<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\TaskProcessing;

use Exception;
use OCA\Watsonx\AppInfo\Application;
use OCA\Watsonx\Service\WatsonxAPIService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;
use RuntimeException;

class TextToTextChatWithToolsProvider implements ISynchronousProvider {

	public function __construct(
		private WatsonxAPIService $watsonxAPIService,
		private IAppConfig $appConfig,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2text:chatwithtools';
	}

	public function getName(): string {
		return $this->watsonxAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToTextChatWithTools::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->watsonxAPIService->getExpTextProcessingTime();
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
		if ($userPrompt === '') {
			$userPrompt = null;
		}

		if (!isset($input['system_prompt']) || !is_string($input['system_prompt'])) {
			throw new RuntimeException('Invalid system_prompt');
		}
		$systemPrompt = $input['system_prompt'];

		if (!isset($input['tool_message']) || !is_string($input['tool_message'])) {
			throw new RuntimeException('Invalid tool_message');
		}
		$toolMessage = $input['tool_message'];
		if ($toolMessage === '') {
			$toolMessage = null;
		}

		if (!isset($input['tools']) || !is_string($input['tools'])) {
			throw new RuntimeException('Invalid tools');
		}
		$tools = json_decode($input['tools']);
		if (!is_array($tools) || !\array_is_list($tools)) {
			throw new RuntimeException('Invalid JSON tools');
		}

		if (!isset($input['history']) || !is_array($input['history']) || !\array_is_list($input['history'])) {
			throw new RuntimeException('Invalid history');
		}
		$history = $input['history'];

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		try {
			$completion = $this->watsonxAPIService->createChatCompletion(
				$userId, $adminModel, $userPrompt, $systemPrompt, $history, 1, $maxTokens, null, $toolMessage, $tools
			);
		} catch (Exception $e) {
			throw new RuntimeException('Watsonx.ai request failed: ' . $e->getMessage());
		}
		if (count($completion['messages']) > 0 || count($completion['tool_calls']) > 0) {
			$endTime = time();
			$this->watsonxAPIService->updateExpTextProcessingTime($endTime - $startTime);
			return [
				'output' => array_pop($completion['messages']) ?? '',
				'tool_calls' => array_pop($completion['tool_calls']) ?? '',
			];
		}

		throw new RuntimeException('No result in watsonx.ai response.');
	}
}
