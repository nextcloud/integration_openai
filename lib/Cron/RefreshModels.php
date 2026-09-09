<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Cron;

use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServicesService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class RefreshModels extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private OpenAiAPIService $openAIAPIService,
		private ServicesService $servicesService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60 * 24); // Daily
	}

	protected function run($argument) {
		$this->logger->debug('Run daily model refresh job');
		foreach ($this->servicesService->getServices() as $service) {
			try {
				$this->openAIAPIService->getModels(null, $service, true);
			} catch (\Throwable $e) {
				$this->logger->info('Could not refresh the model list of service ' . $service->getId(), ['exception' => $e]);
			}
		}
	}
}
