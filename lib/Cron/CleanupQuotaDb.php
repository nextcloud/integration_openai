<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Cron;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCA\OpenAi\Service\OpenAiSettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class CleanupQuotaDb extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private QuotaUsageMapper $quotaUsageMapper,
		private LoggerInterface $logger,
		private OpenAiSettingsService $openAiSettingsService,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60 * 24); // Daily
	}

	protected function run($argument) {
		$this->logger->debug('Run cleanup job for OpenAI quota db');
		$quota = $this->openAiSettingsService->getQuotaPeriod();
		$quotaDays = $quota['length'];
		if ($quota['unit'] == 'month') {
			$quotaDays *= 30;
		}
		$days = $this->openAiSettingsService->getUsageStorageTime();
		$this->quotaUsageMapper->cleanupQuotaUsages(
			// The mimimum period is limited to DEFAULT_QUOTA_PERIOD to not lose
			// the stored quota usage data below this limit.
			max($quotaDays, $days, Application::DEFAULT_QUOTA_PERIOD)
		);

	}
}
