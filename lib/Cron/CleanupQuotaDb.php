<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\Cron;

use OCA\Watsonx\AppInfo\Application;
use OCA\Watsonx\Db\QuotaUsageMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class CleanupQuotaDb extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private QuotaUsageMapper $quotaUsageMapper,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60 * 24); // Daily
	}

	protected function run($argument) {
		$this->logger->debug('Run cleanup job for watsonx.ai quota db');
		$this->quotaUsageMapper->cleanupQuotaUsages(
			// The mimimum period is limited to DEFAULT_QUOTA_PERIOD to not lose
			// the stored quota usage data below this limit.
			max(
				intval($this->appConfig->getValueString(
					Application::APP_ID,
					'quota_period',
					strval(Application::DEFAULT_QUOTA_PERIOD)
				)),
				Application::DEFAULT_QUOTA_PERIOD
			)
		);

	}
}
