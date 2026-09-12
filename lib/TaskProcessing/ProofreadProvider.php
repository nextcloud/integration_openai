<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\Service\ChunkService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServiceConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\TextToTextProofread;

class ProofreadProvider implements ISynchronousProvider {
	use ProviderIdentity;

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IL10N $l,
		private ChunkService $chunkService,
		private ServiceConfig $service,
		private string $model,
	) {
	}

	public function getId(): string {
		return $this->buildProviderId('text2text:proofread');
	}

	public function getName(): string {
		return $this->buildProviderName();
	}

	public function getTaskTypeId(): string {
		return TextToTextProofread::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime($this->service);
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		return [
			'strictness' => new ShapeDescriptor(
				$this->l->t('Strictness'),
				$this->l->t('How thoroughly to check spelling and grammar.'),
				EShapeType::Enum
			),
			'max_tokens' => new ShapeDescriptor(
				$this->l->t('Maximum output words'),
				$this->l->t('The maximum number of words/tokens that can be generated in the completion.'),
				EShapeType::Number
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [
			'strictness' => [
				new ShapeEnumValue($this->l->t('Minimal'), 'minimal'),
				new ShapeEnumValue($this->l->t('Standard'), 'standard'),
				new ShapeEnumValue($this->l->t('Strict'), 'strict'),
			],
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [
			'strictness' => 'standard',
			'max_tokens' => $this->service->getMaxTokens(),
		];
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

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new ProcessingException('Invalid prompt');
		}
		$textInput = $input['input'];
		$strictness = 'standard';
		if (isset($input['strictness']) && is_string($input['strictness'])) {
			$strictness = $input['strictness'];
		}
		$systemPrompt = $this->getSystemPrompt($strictness);

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		$model = $this->model;

		$chunks = $this->chunkService->chunkSplitPrompt($this->service, $textInput, true, $maxTokens);
		$result = '';
		$increase = 1.0 / ((float)count($chunks) + 1.0);
		$progress = 0.0;

		foreach ($chunks as $textInput) {
			try {
				if ($this->service->isUsingOpenAi() || $this->service->getChatEndpointEnabled()) {
					$completion = $this->openAiAPIService->createChatCompletion($userId, $this->service, $model, $textInput, $systemPrompt, null, 1, $maxTokens);
					$completion = $completion['messages'];
				} else {
					$prompt = $systemPrompt . ' Here is the text:' . "\n\n" . $textInput;
					$completion = $this->openAiAPIService->createCompletion($userId, $this->service, $prompt, 1, $model, $maxTokens);
				}
			} catch (UserFacingProcessingException $e) {
				throw $e;
			} catch (\Throwable $e) {
				throw new ProcessingException('OpenAI/LocalAI request failed: ' . $e->getMessage());
			}
			if (count($completion) > 0) {
				$result .= array_pop($completion);
				$progress += $increase;
				$running = $reportProgress($progress);
				if (!$running) {
					throw new ProcessingException('OpenAI/LocalAI task cancelled');
				}
				continue;
			}

			throw new ProcessingException('No result in OpenAI/LocalAI response.');
		}
		if (count($chunks) > 1) {
			$systemPrompt = 'Repeat the proofread feedback list. Ensure that no information is lost, but also not duplicated. ';
			try {
				if ($this->service->isUsingOpenAi() || $this->service->getChatEndpointEnabled()) {
					$completion = $this->openAiAPIService->createChatCompletion($userId, $this->service, $model, $result, $systemPrompt, null, 1, $maxTokens);
					$completion = $completion['messages'];
				} else {
					$prompt = $systemPrompt . ' Here is the text:' . "\n\n" . $result;
					$completion = $this->openAiAPIService->createCompletion($userId, $this->service, $prompt, 1, $model, $maxTokens);
				}
			} catch (UserFacingProcessingException $e) {
				throw $e;
			} catch (\Throwable $e) {
				throw new ProcessingException('OpenAI/LocalAI request failed: ' . $e->getMessage());
			}
			if (count($completion) > 0) {
				$result = array_pop($completion);
			}
		}
		$progress += $increase;
		$reportProgress($progress);
		$endTime = time();
		$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime, $this->service);
		return ['output' => $result];
	}

	private function getSystemPrompt(string $strictness): string {
		$instruction = match ($strictness) {
			'minimal' => 'List only grammatical and spelling errors that clearly affect meaning or readability, and list how to correct them.',
			'strict' => 'List every conceivable issue, including minor grammar rules, and list how to correct them. Also flag redundancy, and phrasing that could be clearer.',
			default => 'List all spelling and grammar mistakes and list how to correct them.',
		};

		return 'Proofread the following text. ' . $instruction . ' Output only the list.';
	}
}
