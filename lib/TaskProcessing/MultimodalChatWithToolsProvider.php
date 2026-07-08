<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ISynchronousOptionsAwareProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\SynchronousProviderOptions;
use OCP\TaskProcessing\TaskTypes\MultimodalChatWithTools;

class MultimodalChatWithToolsProvider implements IProvider, ISynchronousOptionsAwareProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2text:multimodal-chatwithtools';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return MultimodalChatWithTools::ID;
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
		return [
			'reasoning' => new ShapeDescriptor(
				$this->l->t('Reasoning content'),
				$this->l->t('The model reasoning behind the output'),
				EShapeType::Text,
			),
		];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(
		?string $userId, array $input, callable $reportProgress, SynchronousProviderOptions $options = new SynchronousProviderOptions(),
	): array {
		$reportOutput = $options->getReportIntermediateOutput();
		$preferStreaming = false; //$options->getPreferStreaming();
		$startTime = time();
		$adminModel = $this->openAiSettingsService->getAdminDefaultCompletionModelId();

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new ProcessingException('Invalid input');
		}
		$userPrompt = $input['input'];
		if ($userPrompt === '') {
			$userPrompt = null;
		}

		if (!isset($input['system_prompt']) || !is_string($input['system_prompt'])) {
			throw new ProcessingException('Invalid system_prompt');
		}
		$systemPrompt = $input['system_prompt'];

		if (!isset($input['tool_message']) || !is_string($input['tool_message'])) {
			throw new ProcessingException('Invalid tool_message');
		}
		$toolMessage = $input['tool_message'];
		if ($toolMessage === '') {
			$toolMessage = null;
		}

		if (!isset($input['tools']) || !is_string($input['tools'])) {
			throw new ProcessingException('Invalid tools');
		}
		$tools = json_decode($input['tools']);
		if (!is_array($tools) || !\array_is_list($tools)) {
			throw new ProcessingException('Invalid JSON tools');
		}

		if (!isset($input['history']) || !is_array($input['history']) || !\array_is_list($input['history'])) {
			throw new ProcessingException('Invalid history');
		}
		$history = $input['history'];

		if (!isset($input['input_attachments']) || !is_array($input['input_attachments'])) {
			throw new ProcessingException('Invalid input_attachments');
		}
		$inputAttachments = $input['input_attachments'];

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		try {
			if ($preferStreaming) {
				$chunks = $this->openAiAPIService->createStreamedChatCompletion(
					$userId, $adminModel, $userPrompt, $systemPrompt, $history, 1, $maxTokens, null, $toolMessage, $tools, null, null, $inputAttachments
				);
				$time = microtime(true);
				$streamedOutput = '';
				$streamedReasoning = '';
				foreach ($chunks as $chunk) {
					if (!in_array($chunk['kind'] ?? null, ['content', 'reasoning_content'], true)) {
						continue;
					}
					if ($chunk['kind'] === 'reasoning_content') {
						$streamedReasoning .= $chunk['text'];
					} elseif ($chunk['kind'] === 'content') {
						$streamedOutput .= $chunk['text'];
					}
					// we don't report more often than every 250ms
					if (microtime(true) - $time >= 0.25) {
						$running = $reportOutput([
							'output' => $streamedOutput,
							'reasoning' => $streamedReasoning,
						]);
						if (!$running) {
							throw new ProcessingException('OpenAI/LocalAI task cancelled');
						}
						$time = microtime(true);
					}
				}
				if ($streamedOutput !== '' || $streamedReasoning !== '') {
					$running = $reportOutput([
						'output' => $streamedOutput,
						'reasoning' => $streamedReasoning,
					]);
					if (!$running) {
						throw new ProcessingException('OpenAI/LocalAI task cancelled');
					}
				}
				$returnValue = $chunks->getReturn();
			} else {
				$returnValue = $this->openAiAPIService->createChatCompletion(
					$userId, $adminModel, $userPrompt, $systemPrompt, $history, 1, $maxTokens, null, $toolMessage, $tools, null, null, $inputAttachments
				);
			}
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			xdebug_break();
			throw new ProcessingException('OpenAI/LocalAI request failed: ' . $e->getMessage());
		}
		if (count($returnValue['messages']) > 0 || count($returnValue['tool_calls']) > 0) {
			$endTime = time();
			$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
			var_dump($returnValue);
			return [
				'output' => array_pop($returnValue['messages']) ?? '',
				'output_attachments' => [],
				'reasoning' => count($returnValue['reasoning_messages']) > 0 ? array_pop($returnValue['reasoning_messages']) : '',
				'tool_calls' => array_pop($returnValue['tool_calls']) ?? '',
			];
		}

		throw new ProcessingException('No result in OpenAI/LocalAI response.');
	}
}
