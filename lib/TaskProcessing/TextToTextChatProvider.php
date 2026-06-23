<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
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
use OCP\TaskProcessing\TaskTypes\TextToTextChat;

class TextToTextChatProvider implements IProvider, ISynchronousOptionsAwareProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2text:chat';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToTextChat::ID;
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
			'memories' => new ShapeDescriptor(
				$this->l->t('Memories'),
				$this->l->t('The memories to be injected into the chat session.'),
				EShapeType::ListOfTexts
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
		$preferStreaming = $options->getPreferStreaming();
		$startTime = time();
		$adminModel = $this->openAiSettingsService->getAdminDefaultCompletionModelId();

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new ProcessingException('Invalid input');
		}
		$userPrompt = $input['input'];

		if (!isset($input['system_prompt']) || !is_string($input['system_prompt'])) {
			throw new ProcessingException('Invalid system_prompt');
		}
		$systemPrompt = $input['system_prompt'];

		if (isset($input['memories']) && is_array($input['memories']) && count($input['memories'])) {
			/** @psalm-suppress InvalidArgument */
			$systemPrompt .= "\n\nYou can remember things from other conversations with the user. If they are relevant, take into account the following memories:\n" . implode("\n\n", $input['memories']) . "\n\nDo not mention these memories explicitly. You may use them as context, but do not repeat them. At most, you can mention that you remember something.";
		}

		if (!isset($input['history']) || !is_array($input['history'])) {
			throw new ProcessingException('Invalid history');
		}
		$history = $input['history'];

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		try {
			if ($preferStreaming) {
				$chunks = $this->openAiAPIService->createStreamedChatCompletion($userId, $adminModel, $userPrompt, $systemPrompt, $history, 1, $maxTokens);
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
						$reportOutput([
							'output' => $streamedOutput,
							'reasoning' => $streamedReasoning,
						]);
						$time = microtime(true);
					}
				}
				if ($streamedOutput !== '' || $streamedReasoning !== '') {
					$reportOutput([
						'output' => $streamedOutput,
						'reasoning' => $streamedReasoning,
					]);
				}
				$returnValue = $chunks->getReturn();
				$completion = $returnValue['messages'];
				$reasoning = $returnValue['reasoning_messages'];
			} else {
				$returnValue = $this->openAiAPIService->createChatCompletion($userId, $adminModel, $userPrompt, $systemPrompt, $history, 1, $maxTokens);
				$completion = $returnValue['messages'];
				$reasoning = $returnValue['reasoning_messages'];
			}
		} catch (UserFacingProcessingException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new ProcessingException('OpenAI/LocalAI request failed: ' . $e->getMessage());
		}
		if (count($completion) > 0) {
			$endTime = time();
			$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
			return [
				'output' => array_pop($completion),
				'reasoning' => count($reasoning) > 0 ? array_pop($reasoning) : '',
			];
		}

		throw new ProcessingException('No result in OpenAI/LocalAI response.');
	}
}
