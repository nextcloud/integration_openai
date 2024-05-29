<?php

declare(strict_types=1);

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Files\File;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\AudioToText;
use Psr\Log\LoggerInterface;
use RuntimeException;

class STTProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private LoggerInterface $logger,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-audio2text';
	}

	public function getName(): string {
		return $this->openAiAPIService->getServiceName() . '-sync';
	}

	public function getTaskTypeId(): string {
		return AudioToText::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime();
	}

	public function getOptionalInputShape(): array {
		return [
			'temperature' => new ShapeDescriptor(
				$this->l->t('Temperature'),
				$this->l->t('The sampling temperature, between 0 and 1. Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic. If set to 0, the model will use log probability to automatically increase the temperature until certain thresholds are hit.'),
				EShapeType::Number
			),
		];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		if (!isset($input['input']) || !$input['input'] instanceof File || !$input['input']->isReadable()) {
			throw new RuntimeException('Invalid input file');
		}
		$inputFile = $input['input'];

		$temperature = null;
		if (isset($input['temperature'])
			&& (is_float($input['temperature']) || is_int($input['temperature']))) {
			$temperature = $input['temperature'];
		}

		$extraParams = $temperature === null
			? null
			: ['temperature' => $temperature];

		try {
			$transcription = $this->openAiAPIService->transcribeFile($userId, $inputFile);
			return ['output' => $transcription];
		} catch(\Exception $e) {
			$this->logger->warning('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new \RuntimeException('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage());
		}
	}
}
