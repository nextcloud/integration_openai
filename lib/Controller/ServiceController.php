<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Controller;

use Exception;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServicesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Admin management of the connected services, and the per-user credentials
 * users can provide for them.
 */
class ServiceController extends Controller {
	/** Properties that may only be changed with a confirmed password */
	private const SENSITIVE_PROPERTIES = ['url', 'api_key', 'basic_user', 'basic_password'];

	public function __construct(
		string $appName,
		IRequest $request,
		private ServicesService $servicesService,
		private OpenAiAPIService $openAiAPIService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List all configured services, without their secrets
	 */
	public function index(): DataResponse {
		return new DataResponse($this->servicesService->getServicesForFrontend());
	}

	/**
	 * Add a new, empty service. Its properties are set through update().
	 */
	public function create(): DataResponse {
		try {
			$service = $this->servicesService->addService();
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($service->jsonSerializeRedacted());
	}

	/**
	 * Update the given properties of a service
	 *
	 * The URL and the credentials can only be set through
	 * {@see self::updateSensitive()}.
	 *
	 * @param string $id ID of the service
	 * @param array $values properties to change
	 */
	public function update(string $id, array $values): DataResponse {
		foreach (self::SENSITIVE_PROPERTIES as $property) {
			// array_key_exists, so that an explicit null does not slip past
			if (array_key_exists($property, $values)) {
				return new DataResponse(['error' => $property . ' can only be set through the sensitive endpoint'], Http::STATUS_BAD_REQUEST);
			}
		}
		return $this->doUpdate($id, $values);
	}

	/**
	 * Update the URL and the credentials of a service
	 *
	 * Secrets that are sent back unchanged (as the placeholder the frontend
	 * received) are kept.
	 *
	 * @param string $id ID of the service
	 * @param array $values properties to change
	 */
	#[PasswordConfirmationRequired]
	public function updateSensitive(string $id, array $values): DataResponse {
		return $this->doUpdate($id, $values);
	}

	/**
	 * @param array $values properties to change
	 */
	private function doUpdate(string $id, array $values): DataResponse {
		try {
			$service = $this->servicesService->updateService($id, $values);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($service->jsonSerializeRedacted());
	}

	/**
	 * Delete a service, which unregisters all providers it exposed
	 *
	 * @param string $id ID of the service
	 */
	#[PasswordConfirmationRequired]
	public function destroy(string $id): DataResponse {
		try {
			$this->servicesService->deleteService($id);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse('');
	}

	/**
	 * The model list of a service, freshly fetched from it
	 *
	 * @param string $id ID of the service
	 */
	public function models(string $id): DataResponse {
		try {
			$service = $this->servicesService->getServiceOrFail($id);
			return new DataResponse($this->openAiAPIService->getModels(null, $service));
		} catch (Exception $e) {
			$code = $e->getCode() === 0 ? Http::STATUS_BAD_REQUEST : intval($e->getCode());
			return new DataResponse(['error' => $e->getMessage()], $code);
		}
	}

	/**
	 * Detect which modalities a service supports and switch off the others
	 *
	 * @param string $id ID of the service
	 */
	public function autoDetectModalities(string $id): DataResponse {
		try {
			$service = $this->servicesService->getServiceOrFail($id);
			return new DataResponse($this->openAiAPIService->autoDetectModalities($service));
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * The credentials the current user provided for each service, redacted
	 */
	#[NoAdminRequired]
	public function userCredentials(): DataResponse {
		if ($this->userId === null) {
			return new DataResponse('', Http::STATUS_UNAUTHORIZED);
		}
		return new DataResponse($this->servicesService->getUserCredentialsForFrontend($this->userId));
	}

	/**
	 * Store the credentials the current user provides for one service
	 *
	 * @param string $id ID of the service
	 * @param array $values any of api_key, basic_user, basic_password
	 */
	#[NoAdminRequired]
	#[PasswordConfirmationRequired]
	public function setUserCredentials(string $id, array $values): DataResponse {
		if ($this->userId === null) {
			return new DataResponse('', Http::STATUS_UNAUTHORIZED);
		}
		try {
			$this->servicesService->setUserCredentials($this->userId, $id, $values);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse('');
	}
}
