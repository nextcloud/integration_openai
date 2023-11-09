<?php

declare(strict_types=1);

/**
 * @author Sami Finnilä <sami.finnila@gmail.com>
 * @copyright Sami Finnilä 2023
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\OpenAi\Cron;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\QuotaUsageMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class CleanupQuotaDb extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private QuotaUsageMapper $quotaUsageMapper,
		private LoggerInterface $logger,
		private IConfig $config
	) {
		parent::__construct($time);
		$this->setInterval(60 * 60 * 24); // Daily
	}

	protected function run($argument) {
		$this->logger->debug('Run cleanup job for OpenAI quota db');
		$this->quotaUsageMapper->cleanupQuotaUsages(
			// The mimimum period is limited to DEFAULT_QUOTA_PERIOD
			max(
				intval($this->config->getAppValue(
					Application::APP_ID,
					'quota_period',
					strval(Application::DEFAULT_QUOTA_PERIOD)
				)),
				Application::DEFAULT_QUOTA_PERIOD
			)
		);

	}
}
