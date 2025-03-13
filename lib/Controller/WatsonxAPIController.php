<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Watsonx\Controller;

use Exception;
use OCA\Watsonx\Service\WatsonxAPIService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class WatsonxAPIController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private WatsonxAPIService $watsonxAPIService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getModels(): DataResponse {
		try {
			$response = $this->watsonxAPIService->getModels($this->userId);
			return new DataResponse($response);
		} catch (Exception $e) {
			$code = $e->getCode() === 0 ? Http::STATUS_BAD_REQUEST : intval($e->getCode());
			return new DataResponse(['error' => $e->getMessage()], $code);
		}
	}

	/**
	 * Get quota usage and limits
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getUserQuotaInfo(): DataResponse {
		try {
			$info = $this->watsonxAPIService->getUserQuotaInfo($this->userId);
		} catch (Exception $e) {
			$code = $e->getCode() === 0 ? Http::STATUS_BAD_REQUEST : intval($e->getCode());
			return new DataResponse(['error' => $e->getMessage()], $code);
		}

		return new DataResponse($info);
	}

	/**
	 * Get quota usage and limits for the whole instance
	 * Admin only!
	 * @return DataResponse
	 */
	public function getAdminQuotaInfo(): DataResponse {
		try {
			$info = $this->watsonxAPIService->getAdminQuotaInfo();
		} catch (Exception $e) {
			$code = $e->getCode() === 0 ? Http::STATUS_BAD_REQUEST : intval($e->getCode());
			return new DataResponse(['error' => $e->getMessage()], $code);
		}

		return new DataResponse($info);
	}
}
