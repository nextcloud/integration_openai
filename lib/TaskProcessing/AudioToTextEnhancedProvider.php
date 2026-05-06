<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;
use Throwable;

// Built on top of the AudioToTextProvider to add paragraph reformatting.
class AudioToTextEnhancedProvider extends AudioToTextProvider {
	private const REFORMAT_PARAGRAPHS_TASK_TYPE_ID = 'core:text2text:reformatparagraphs';

	private IManager $taskProcessingManager;

	public function __construct(
		OpenAiAPIService $openAiAPIService,
		LoggerInterface $logger,
		IAppConfig $appConfig,
		IL10N $l,
		IManager $taskProcessingManager,
	) {
		parent::__construct($openAiAPIService, $logger, $appConfig, $l);
		$this->taskProcessingManager = $taskProcessingManager;
	}

	public function getId(): string {
		return parent::getId() . '-enhanced';
	}

	public function getName(): string {
		return parent::getName() . ' ' . $this->l->t('(with paragraph reformatting)');
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$transcription = parent::process($userId, $input, $reportProgress)['output'];

		$reformatTask = new Task(
			self::REFORMAT_PARAGRAPHS_TASK_TYPE_ID,
			['input' => $transcription],
			Application::APP_ID,
			$userId,
			'audio2text_enhanced',
		);

		try {
			$finished = $this->taskProcessingManager->runTask($reformatTask);
			$output = $finished->getOutput();
			if (is_array($output) && isset($output['output']) && is_string($output['output']) && $output['output'] !== '') {
				return ['output' => $output['output']];
			}
			$this->logger->warning('ReformatParagraphs follow-up task returned no usable output, falling back to raw transcription');
		} catch (Throwable $e) {
			$this->logger->warning('ReformatParagraphs follow-up task failed, falling back to raw transcription: ' . $e->getMessage(), ['exception' => $e]);
		}

		return ['output' => $transcription];
	}
}
