<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\Controller;

use Exception;
use OCA\Watsonx\Service\WatsonxSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ConfigController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private WatsonxSettingsService $watsonxSettingsService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Set config values
	 *
	 * @param array $values key/value pairs to store in config
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function setUserConfig(array $values): DataResponse {
		if (isset($values['api_key']) || isset($values['basic_password']) || isset($values['basic_user'])) {
			return new DataResponse('', Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->watsonxSettingsService->setUserConfig($this->userId, $values);
		} catch (Exception $e) {
			return new DataResponse($e->getMessage(), Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse('');
	}

	/**
	 * Set sensitive config values
	 *
	 * @param array $values key/value pairs to store in config
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	public function setSensitiveUserConfig(array $values): DataResponse {
		try {
			$this->watsonxSettingsService->setUserConfig($this->userId, $values);
		} catch (Exception $e) {
			return new DataResponse($e->getMessage(), Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse('');
	}

	/**
	 * Set admin config values
	 *
	 * @param array $values key/value pairs to store in app config
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		if (isset($values['api_key']) || isset($values['basic_password']) || isset($values['basic_user']) || isset($values['url'])) {
			return new DataResponse('', Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->watsonxSettingsService->setAdminConfig($values);
		} catch (Exception $e) {
			return new DataResponse($e->getMessage(), Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse('');
	}

	/**
	 * Set sensitive admin config values
	 *
	 * @param array $values key/value pairs to store in app config
	 * @return DataResponse
	 */
	#[PasswordConfirmationRequired]
	public function setSensitiveAdminConfig(array $values): DataResponse {
		try {
			$this->watsonxSettingsService->setAdminConfig($values);
		} catch (Exception $e) {
			return new DataResponse($e->getMessage(), Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse('');
	}
}
