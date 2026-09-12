<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use DateInterval;
use DateTime;
use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\PreConditionNotMetException;

/**
 * Instance-wide settings of the app.
 *
 * Everything that belongs to one connected service (URL, credentials, request
 * behaviour, selected models, quota amounts) lives in {@see ServicesService}
 * instead. What remains here is global: the quota period, how long usage is
 * stored and the user preferences that are not tied to a service.
 */
class OpenAiSettingsService {
	private const ADMIN_CONFIG_TYPES = [
		'quota_period' => 'array',
		'usage_storage_time' => 'integer',
	];

	private const USER_CONFIG_TYPES = [
		'stt_language' => 'string',
	];

	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Gets the timestamp of the beginning of the quota period
	 *
	 * @return int
	 * @throws Exception
	 */
	public function getQuotaStart(): int {
		$quotaPeriod = $this->getQuotaPeriod();
		$now = new DateTime();

		if ($quotaPeriod['unit'] === 'day') {
			// Get a timestamp of the beginning of the time period
			$periodStart = $now->sub(new DateInterval('P' . $quotaPeriod['length'] . 'D'));
		} else {
			$periodStart = new DateTime(date('Y-m-' . $quotaPeriod['day']));
			// Ensure that this isn't in the future
			if ($periodStart > $now) {
				$periodStart = $periodStart->sub(new DateInterval('P1M'));
			}
			if ($quotaPeriod['length'] > 1) {
				// Calculate number of months since 2000-01 to ensure the start month is consistent
				$startDate = new DateTime('2000-01-' . $quotaPeriod['day']);
				$months = $startDate->diff($periodStart)->m + $startDate->diff($periodStart)->y * 12;
				$remainder = $months % $quotaPeriod['length'];
				$periodStart = $periodStart->sub(new DateInterval('P' . $remainder . 'M'));
			}
		}
		return $periodStart->getTimestamp();
	}

	/**
	 * Gets the timestamp of the end of the quota period
	 * if the period is floating, then this will be the current time
	 *
	 * @return int
	 * @throws Exception
	 */
	public function getQuotaEnd(): int {
		$quotaPeriod = $this->getQuotaPeriod();
		$now = new DateTime();

		if ($quotaPeriod['unit'] === 'day') {
			// Get a timestamp of the beginning of the time period
			$periodEnd = $now;
		} else {
			$periodEnd = new DateTime(date('Y-m-' . $quotaPeriod['day']));
			// Ensure that this isn't in the past
			if ($periodEnd < $now) {
				$periodEnd = $periodEnd->add(new DateInterval('P1M'));
			}
			if ($quotaPeriod['length'] > 1) {
				// Calculate number of months since 2000-01 to ensure the start month is consistent
				$startDate = new DateTime('2000-01-' . $quotaPeriod['day']);
				$months = $startDate->diff($periodEnd)->m + $startDate->diff($periodEnd)->y * 12;
				$remainder = $months % $quotaPeriod['length'];
				if ($remainder != 0) {
					$periodEnd = $periodEnd->add(new DateInterval('P' . $quotaPeriod['length'] - $remainder . 'M'));
				}
			}
		}
		return $periodEnd->getTimestamp();
	}

	////////////////////////////////////////////
	//////////// Getters for settings //////////

	/**
	 * @return array
	 */
	public function getQuotaPeriod(): array {
		$value = json_decode(
			$this->appConfig->getValueString(Application::APP_ID, 'quota_period', json_encode(Application::DEFAULT_QUOTA_CONFIG), lazy: true),
			true
		) ?: Application::DEFAULT_QUOTA_CONFIG;
		// Migrate from old quota period to new one
		if (is_int($value)) {
			$value = ['length' => $value];
		}
		foreach (Application::DEFAULT_QUOTA_CONFIG as $key => $defaultValue) {
			if (!isset($value[$key])) {
				$value[$key] = $defaultValue;
			}
		}
		return $value;
	}

	public function getUsageStorageTime(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'usage_storage_time', Application::DEFAULT_QUOTA_PERIOD, lazy: true);
	}

	/**
	 * @param string|null $userId
	 * @return string
	 */
	public function getUserSTTLanguage(?string $userId): string {
		return $this->config->getUserValue($userId, Application::APP_ID, 'stt_language', 'detect_language');
	}

	/**
	 * Get the instance-wide admin config for the settings page
	 *
	 * @return array{quota_period: array, usage_storage_time: int}
	 */
	public function getAdminConfig(): array {
		return [
			'quota_period' => $this->getQuotaPeriod(),
			'usage_storage_time' => $this->getUsageStorageTime(),
		];
	}

	/**
	 * Get the user config for the settings page
	 *
	 * @return array{stt_language: string}
	 */
	public function getUserConfig(string $userId): array {
		return [
			'stt_language' => $this->getUserSTTLanguage($userId),
		];
	}

	////////////////////////////////////////////
	//////////// Setters for settings //////////

	/**
	 * Setter for quotaPeriod; minimum is 1 day.
	 * Days are floating, and months are set dates
	 * @param array $quotaPeriod
	 * @return void
	 * @throws Exception
	 */
	public function setQuotaPeriod(array $quotaPeriod): void {
		if (!isset($quotaPeriod['length']) || !is_int($quotaPeriod['length'])) {
			throw new Exception('Invalid quota period length');
		}
		if ($quotaPeriod['length'] < 1) {
			throw new Exception('Invalid quota period length');
		}
		if (!isset($quotaPeriod['unit']) || !is_string($quotaPeriod['unit'])) {
			throw new Exception('Invalid quota period unit');
		}
		// Checks month period
		if ($quotaPeriod['unit'] === 'month') {
			if (!isset($quotaPeriod['day']) || !is_int($quotaPeriod['day'])) {
				throw new Exception('Invalid quota period day');
			}
			if ($quotaPeriod['day'] < 1) {
				throw new Exception('Invalid quota period day');
			}
			if ($quotaPeriod['day'] > 28) {
				throw new Exception('Invalid quota period day');
			}
		} elseif ($quotaPeriod['unit'] !== 'day') {
			throw new Exception('Invalid quota period unit');
		}
		$this->appConfig->setValueString(Application::APP_ID, 'quota_period', json_encode($quotaPeriod), lazy: true);
	}

	/**
	 * @param int $usageStorageTime
	 * @return void
	 */
	public function setUsageStorageTime(int $usageStorageTime): void {
		$usageStorageTime = max(1, $usageStorageTime);
		$this->appConfig->setValueInt(Application::APP_ID, 'usage_storage_time', $usageStorageTime, lazy: true);
	}

	/**
	 * @param string $userId
	 * @param string $language
	 * @throws PreConditionNotMetException
	 */
	public function setUserSTTLanguage(string $userId, string $language): void {
		$this->config->setUserValue($userId, Application::APP_ID, 'stt_language', $language);
	}

	/**
	 * Set the instance-wide admin config
	 *
	 * @param array<string, mixed> $adminConfig
	 * @throws Exception
	 */
	public function setAdminConfig(array $adminConfig): void {
		foreach ($adminConfig as $key => $value) {
			if (!isset(self::ADMIN_CONFIG_TYPES[$key])) {
				throw new Exception('Invalid config key: ' . $key);
			}
			if (gettype($value) !== self::ADMIN_CONFIG_TYPES[$key]) {
				throw new Exception('Invalid type for key: ' . $key . '. Expected ' . self::ADMIN_CONFIG_TYPES[$key] . ', got ' . gettype($value));
			}
		}

		// Validation of the input values is done in the individual setters
		if (isset($adminConfig['quota_period'])) {
			$this->setQuotaPeriod($adminConfig['quota_period']);
		}
		if (isset($adminConfig['usage_storage_time'])) {
			$this->setUsageStorageTime($adminConfig['usage_storage_time']);
		}
	}

	/**
	 * Set the user config for the settings page
	 *
	 * @param array<string, mixed> $userConfig
	 * @throws Exception
	 */
	public function setUserConfig(string $userId, array $userConfig): void {
		foreach ($userConfig as $key => $value) {
			if (!isset(self::USER_CONFIG_TYPES[$key])) {
				throw new Exception('Invalid config key: ' . $key);
			}
			if (gettype($value) !== self::USER_CONFIG_TYPES[$key]) {
				throw new Exception('Invalid type for key: ' . $key . '. Expected ' . self::USER_CONFIG_TYPES[$key] . ', got ' . gettype($value));
			}
		}

		if (isset($userConfig['stt_language'])) {
			$this->setUserSTTLanguage($userId, $userConfig['stt_language']);
		}
	}
}
