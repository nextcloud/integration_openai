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
use OCA\Watsonx\Service\WatsonxSettingsService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToTextProofread;
use RuntimeException;

class ProofreadProvider implements ISynchronousProvider {

	public function __construct(
		private WatsonxAPIService $watsonxAPIService,
		private IAppConfig $appConfig,
		private WatsonxSettingsService $watsonxSettingsService,
		private IL10N $l,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2text:proofread';
	}

	public function getName(): string {
		return $this->watsonxAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToTextProofread::ID;
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
			'model' => new ShapeDescriptor(
				$this->l->t('Model'),
				$this->l->t('The model used to generate the completion'),
				EShapeType::Enum
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [
			'model' => $this->watsonxAPIService->getModelEnumValues($this->userId),
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		$adminModel = $this->watsonxAPIService->isUsingWatsonx()
			? ($this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID)
			: $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id');
		return [
			'max_tokens' => 1000,
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
		$textInput = $input['input'];
		$systemPrompt = 'Proofread the following text. List all spelling and grammar mistakes and how to correct them. Output only the list.';

		$maxTokens = null;
		if (isset($input['max_tokens']) && is_int($input['max_tokens'])) {
			$maxTokens = $input['max_tokens'];
		}

		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->appConfig->getValueString(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID;
		}

		try {
			if ($this->watsonxAPIService->isUsingWatsonx() || $this->watsonxSettingsService->getChatEndpointEnabled()) {
				$completion = $this->watsonxAPIService->createChatCompletion($userId, $model, $textInput, $systemPrompt, null, 1, $maxTokens);
				$completion = $completion['messages'];
			} else {
				$prompt = $systemPrompt . ' Here is the text:' . "\n\n" . $textInput;
				$completion = $this->watsonxAPIService->createCompletion($userId, $prompt, 1, $model, $maxTokens);
			}
		} catch (Exception $e) {
			throw new RuntimeException('Watsonx request failed: ' . $e->getMessage());
		}
		if (count($completion) > 0) {
			$endTime = time();
			$this->watsonxAPIService->updateExpTextProcessingTime($endTime - $startTime);
			return ['output' => array_pop($completion)];
		}

		throw new RuntimeException('No result in watsonx response.');
	}
}
