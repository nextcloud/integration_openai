<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\ChunkService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextReformulation;
use RuntimeException;

class ReformulateProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IAppConfig $appConfig,
		private OpenAiSettingsService $openAiSettingsService,
		private IL10N $l,
		private ChunkService $chunkService,
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
		return TextToTextReformulation::ID;
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
			'model' => new ShapeDescriptor(
				$this->l->t('Model'),
				$this->l->t('The model used to generate the completion'),
				EShapeType::Enum
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [
			'model' => $this->openAiAPIService->getModelEnumValues($this->userId),
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		$adminModel = $this->openAiAPIService->isUsingOpenAi()
			? ($this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID, lazy: true) ?: Application::DEFAULT_MODEL_ID)
			: $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', lazy: true);
		return [
			'max_tokens' => $this->openAiSettingsService->getMaxTokens(),
			'model' => $adminModel,
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
			throw new RuntimeException('Invalid prompt');
		}
		$prompt = $input['input'];

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID, lazy: true) ?: Application::DEFAULT_MODEL_ID;
		}
		$chunks = $this->chunkService->chunkSplitPrompt($prompt, true, $maxTokens);
		$result = '';
		$increase = 1.0 / (float)count($chunks);
		$progress = 0.0;

		foreach ($chunks as $chunk) {
			$prompt = 'Reformulate the following text. Use the same language as the original text.  Output only the reformulation. Here is the text:' . "\n\n" . $chunk . "\n\n" . 'Do not mention the used language in your reformulation. Here is your reformulation in the same language:';
			try {
				if ($this->openAiAPIService->isUsingOpenAi() || $this->openAiSettingsService->getChatEndpointEnabled()) {
					$completion = $this->openAiAPIService->createChatCompletion($userId, $model, $prompt, null, null, 1, $maxTokens);
					$completion = $completion['messages'];
				} else {
					$completion = $this->openAiAPIService->createCompletion($userId, $prompt, 1, $model, $maxTokens);
				}
			} catch (Exception $e) {
				throw new RuntimeException('OpenAI/LocalAI request failed: ' . $e->getMessage());
			}
			if (count($completion) > 0) {
				$result .= array_pop($completion);
				$progress += $increase;
				$reportProgress($progress);
				continue;
			}

			throw new RuntimeException('No result in OpenAI/LocalAI response.');
		}

		$endTime = time();
		$this->openAiAPIService->updateExpTextProcessingTime($endTime - $startTime);
		return ['output' => $result];
	}
}
