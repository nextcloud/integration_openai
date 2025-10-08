<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Cron;

use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class RefreshModels extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OpenAiAPIService $openAIAPIService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60 * 24); // Daily
	}

	protected function run($argument) {
		$this->logger->debug('Run daily model refresh job');
		$this->openAIAPIService->getModels(null, true);
	}
}
