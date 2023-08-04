<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Julien Veyssier <julien-nc@posteo.net>
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace OCA\OpenAi\SpeechToText;

use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Files\File;
use OCP\IL10N;
use OCP\SpeechToText\ISpeechToTextProvider;
use Psr\Log\LoggerInterface;

class STTProvider implements ISpeechToTextProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private LoggerInterface $logger,
		private IL10N $l,
		private ?string $userId,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->openAiAPIService->isUsingOpenAi()
			? $this->l->t('OpenAI\'s Whisper Speech-To-Text')
			: $this->l->t('LocalAI\'s Whisper Speech-To-Text');
	}

	/**
	 * @inheritDoc
	 */
	public function transcribeFile(File $file): string {
		try {
			return $this->openAiAPIService->transcribeFile($this->userId, $file);
		} catch(\Exception $e) {
			$this->logger->warning('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new \RuntimeException('OpenAI\'s Whisper transcription failed with: ' . $e->getMessage());
		}
	}
}
