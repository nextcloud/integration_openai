<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stores and retrieves the list of connected OpenAI-compatible services.
 *
 * The services are kept as a JSON list in a single app config value, with the
 * secrets of each service encrypted. Every service has an immutable ID which
 * is part of the IDs of the task processing providers it exposes, so renaming
 * a service or changing its URL does not invalidate the admin's provider
 * preferences.
 */
class ServicesService {
	/** @var ServiceConfig[]|null */
	private ?array $servicesCache = null;

	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private ICacheFactory $cacheFactory,
		private ICrypto $crypto,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * All configured services, in the order the admin created them
	 *
	 * @return ServiceConfig[]
	 */
	public function getServices(): array {
		if ($this->servicesCache !== null) {
			return $this->servicesCache;
		}
		$storedString = $this->appConfig->getValueString(Application::APP_ID, Application::SERVICES_CONFIG_KEY, '[]', lazy: true);
		try {
			$stored = json_decode($storedString, true, flags: JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$this->logger->error('Could not decode the stored service list', ['exception' => $e]);
			$stored = [];
		}
		if (!is_array($stored)) {
			$stored = [];
		}

		$services = [];
		foreach ($stored as $values) {
			if (!is_array($values) || !isset($values['id']) || !is_string($values['id'])) {
				continue;
			}
			foreach (ServiceConfig::SECRET_PROPERTIES as $secret) {
				$values[$secret] = $this->decrypt($values[$secret] ?? '');
			}
			$services[] = ServiceConfig::fromArray($values['id'], $values);
		}
		$this->servicesCache = $services;
		return $services;
	}

	/**
	 * @return ServiceConfig[] all services that expose at least one model for the given modality
	 */
	public function getServicesForModality(string $modality): array {
		return array_values(array_filter(
			$this->getServices(),
			static fn (ServiceConfig $service) => count($service->getModels($modality)) > 0,
		));
	}

	public function getService(string $id): ?ServiceConfig {
		foreach ($this->getServices() as $service) {
			if ($service->getId() === $id) {
				return $service;
			}
		}
		return null;
	}

	/**
	 * @throws Exception if the service does not exist
	 */
	public function getServiceOrFail(string $id): ServiceConfig {
		$service = $this->getService($id);
		if ($service === null) {
			throw new Exception('Unknown service: ' . $id);
		}
		return $service;
	}

	/**
	 * The first configured service, used where a single service has to be
	 * picked without further context
	 */
	public function getDefaultService(): ?ServiceConfig {
		return $this->getServices()[0] ?? null;
	}

	public function hasOpenAiService(): bool {
		foreach ($this->getServices() as $service) {
			if ($service->isUsingOpenAi()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create a new service
	 *
	 * @param array<string, mixed> $values
	 * @throws Exception if a value has an invalid type
	 */
	public function addService(array $values = []): ServiceConfig {
		$this->validate($values);
		$services = $this->getServices();
		$services[] = ServiceConfig::fromArray($this->generateId(), $values);
		$this->storeServices($services);
		return $this->getServiceOrFail(end($services)->getId());
	}

	/**
	 * Update the given properties of an existing service. Properties that are
	 * not present in $values are left untouched. A secret set to the
	 * placeholder value is left untouched as well, so the frontend can send
	 * back the redacted representation it received.
	 *
	 * @param array<string, mixed> $values
	 * @throws Exception if the service does not exist or a value has an invalid type
	 */
	public function updateService(string $id, array $values): ServiceConfig {
		$this->validate($values);
		foreach (ServiceConfig::SECRET_PROPERTIES as $secret) {
			if (($values[$secret] ?? null) === Application::SECRET_PLACEHOLDER) {
				unset($values[$secret]);
			}
		}
		$services = $this->getServices();
		$found = false;
		foreach ($services as $index => $service) {
			if ($service->getId() === $id) {
				$services[$index] = $service->with($values);
				$found = true;
				break;
			}
		}
		if (!$found) {
			throw new Exception('Unknown service: ' . $id);
		}
		$this->storeServices($services);
		return $this->getServiceOrFail($id);
	}

	/**
	 * @throws Exception if the service does not exist
	 */
	public function deleteService(string $id): void {
		$services = $this->getServices();
		$remaining = array_values(array_filter(
			$services,
			static fn (ServiceConfig $service) => $service->getId() !== $id,
		));
		if (count($remaining) === count($services)) {
			throw new Exception('Unknown service: ' . $id);
		}
		$this->storeServices($remaining);
		$this->appConfig->deleteKey(Application::APP_ID, Application::MODELS_CACHE_KEY . '_' . $id);
		$this->deleteAllUserCredentials($id);
	}

	/**
	 * Drop the credentials every user provided for a service that is gone, so
	 * that no secret material is left behind.
	 *
	 * The recorded quota usage of the service is deliberately kept: it is real
	 * usage that still counts towards an instance-wide quota rule, and the
	 * cleanup job prunes it with the rest of the usage history.
	 */
	private function deleteAllUserCredentials(string $id): void {
		$keys = array_map(
			fn (string $key) => $this->userKey($id, $key),
			['api_key', 'basic_user', 'basic_password'],
		);
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('userid', 'configkey')
				->from('preferences')
				->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID, IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->in('configkey', $qb->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)));
			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
			foreach ($rows as $row) {
				// through IConfig, so its user value cache stays coherent
				$this->config->deleteUserValue((string)$row['userid'], Application::APP_ID, (string)$row['configkey']);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Could not delete the user credentials of the removed service ' . $id,
				['exception' => $e],
			);
		}
	}

	/**
	 * Replace the whole service list. Used by the migration from the
	 * single-service configuration.
	 *
	 * @param ServiceConfig[] $services
	 */
	public function setServices(array $services): void {
		$this->storeServices(array_values($services));
	}

	/**
	 * Representation of all services for the admin frontend, without secrets
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getServicesForFrontend(): array {
		return array_values(array_map(
			static fn (ServiceConfig $service) => $service->jsonSerializeRedacted(),
			$this->getServices(),
		));
	}

	/**
	 * Return the service as it should be used for requests on behalf of
	 * $userId: with the user's own credentials if they configured any.
	 */
	public function applyUserCredentials(ServiceConfig $service, ?string $userId): ServiceConfig {
		if ($userId === null) {
			return $service;
		}
		$values = [];
		$apiKey = $this->getUserApiKey($userId, $service->getId());
		if ($apiKey !== '') {
			$values['api_key'] = $apiKey;
		}
		$basicUser = $this->getUserBasicUser($userId, $service->getId());
		$basicPassword = $this->getUserBasicPassword($userId, $service->getId());
		if ($basicUser !== '' && $basicPassword !== '') {
			$values['basic_user'] = $basicUser;
			$values['basic_password'] = $basicPassword;
		}
		return $values === [] ? $service : $service->with($values);
	}

	/**
	 * Whether the user provided their own credentials for this service, which
	 * exempts them from the quotas.
	 *
	 * Only the credentials that requests to this service actually use count:
	 * an API key stored for a service that authenticates with basic auth is
	 * never sent, so it must not lift the quotas while the admin's
	 * credentials pay for the requests.
	 */
	public function userHasOwnCredentials(?string $userId, ServiceConfig $service): bool {
		if ($userId === null) {
			return false;
		}
		if ($service->usesBasicAuth()) {
			return $this->getUserBasicUser($userId, $service->getId()) !== ''
				&& $this->getUserBasicPassword($userId, $service->getId()) !== '';
		}
		return $this->getUserApiKey($userId, $service->getId()) !== '';
	}

	public function getUserApiKey(string $userId, string $serviceId): string {
		return $this->getUserSecret($userId, $serviceId, 'api_key');
	}

	public function getUserBasicUser(string $userId, string $serviceId): string {
		return $this->config->getUserValue($userId, Application::APP_ID, $this->userKey($serviceId, 'basic_user'));
	}

	public function getUserBasicPassword(string $userId, string $serviceId): string {
		return $this->getUserSecret($userId, $serviceId, 'basic_password');
	}

	/**
	 * The per-service credentials of a user, for the personal settings page.
	 * Secrets are redacted.
	 *
	 * @return array<string, array{api_key: string, basic_user: string, basic_password: string}>
	 */
	public function getUserCredentialsForFrontend(string $userId): array {
		$credentials = [];
		foreach ($this->getServices() as $service) {
			$apiKey = $this->getUserApiKey($userId, $service->getId());
			$basicPassword = $this->getUserBasicPassword($userId, $service->getId());
			$credentials[$service->getId()] = [
				'api_key' => $apiKey === '' ? '' : Application::SECRET_PLACEHOLDER,
				'basic_user' => $this->getUserBasicUser($userId, $service->getId()),
				'basic_password' => $basicPassword === '' ? '' : Application::SECRET_PLACEHOLDER,
			];
		}
		return $credentials;
	}

	/**
	 * Store the credentials a user provided for one service
	 *
	 * @param array<string, mixed> $values with any of the keys api_key, basic_user, basic_password
	 * @throws Exception if the service does not exist
	 */
	public function setUserCredentials(string $userId, string $serviceId, array $values): void {
		$this->getServiceOrFail($serviceId);
		foreach (['api_key', 'basic_user', 'basic_password'] as $key) {
			if (!isset($values[$key]) || !is_string($values[$key])) {
				continue;
			}
			$value = $values[$key];
			if ($value === Application::SECRET_PLACEHOLDER) {
				continue;
			}
			$configKey = $this->userKey($serviceId, $key);
			if ($value === '') {
				$this->config->deleteUserValue($userId, Application::APP_ID, $configKey);
				continue;
			}
			if ($key === 'basic_user') {
				$this->config->setUserValue($userId, Application::APP_ID, $configKey, $value);
			} else {
				$this->config->setUserValue($userId, Application::APP_ID, $configKey, $this->crypto->encrypt($value));
			}
		}
	}

	/**
	 * @param ServiceConfig[] $services
	 */
	private function storeServices(array $services): void {
		$stored = array_map(function (ServiceConfig $service) {
			$values = $service->jsonSerialize();
			foreach (ServiceConfig::SECRET_PROPERTIES as $secret) {
				$values[$secret] = $values[$secret] === '' ? '' : $this->crypto->encrypt($values[$secret]);
			}
			return $values;
		}, $services);
		$this->appConfig->setValueString(
			Application::APP_ID,
			Application::SERVICES_CONFIG_KEY,
			json_encode($stored, JSON_THROW_ON_ERROR),
			lazy: true,
			sensitive: true,
		);
		$this->servicesCache = null;
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID);
		// the URL or the credentials may have changed, so the cached model
		// lists cannot be trusted anymore
		$cache->clear(Application::MODELS_CACHE_KEY);
		// the quota amounts of a service are part of the cached fallback quota
		// rule, so a changed quota would otherwise not be enforced
		$cache->clear(Application::QUOTA_RULES_CACHE_PREFIX);
	}

	/**
	 * @param array<string, mixed> $values
	 * @throws Exception if a value has an invalid type
	 */
	private function validate(array $values): void {
		foreach ($values as $key => $value) {
			if ($key === 'id') {
				// the ID is not writable
				continue;
			}
			if (!isset(ServiceConfig::PROPERTY_TYPES[$key])) {
				throw new Exception('Invalid service property: ' . $key);
			}
			$expected = ServiceConfig::PROPERTY_TYPES[$key];
			if (in_array($key, ['use_max_completion_tokens_param', 'image_request_auth'], true) && $value === null) {
				// null means "decide based on the service URL"
				continue;
			}
			if (gettype($value) !== $expected) {
				throw new Exception('Invalid type for ' . $key . '. Expected ' . $expected . ', got ' . gettype($value));
			}
		}
		if (isset($values['llm_extra_params']) && $values['llm_extra_params'] !== '') {
			if (!is_array(json_decode((string)$values['llm_extra_params'], true))) {
				throw new Exception('llm_extra_params must be a JSON object');
			}
		}
		if (isset($values['url']) && $values['url'] !== '' && !filter_var($values['url'], FILTER_VALIDATE_URL)) {
			throw new Exception('Invalid service URL');
		}
		if (isset($values['default_image_size']) && $values['default_image_size'] !== ''
			&& preg_match('/^\d+x\d+$/', (string)$values['default_image_size']) !== 1) {
			throw new Exception('Invalid image size value. Expected the format <width>x<height>');
		}
	}

	private function generateId(): string {
		$counter = $this->appConfig->getValueInt(Application::APP_ID, 'service_id_counter', 0) + 1;
		$this->appConfig->setValueInt(Application::APP_ID, 'service_id_counter', $counter);
		return 's' . $counter;
	}

	private function userKey(string $serviceId, string $key): string {
		return 'service_' . $serviceId . '_' . $key;
	}

	private function getUserSecret(string $userId, string $serviceId, string $key): string {
		$stored = $this->config->getUserValue($userId, Application::APP_ID, $this->userKey($serviceId, $key));
		return $this->decrypt($stored);
	}

	private function decrypt(mixed $value): string {
		if (!is_string($value) || $value === '') {
			return '';
		}
		try {
			return $this->crypto->decrypt($value);
		} catch (Throwable $e) {
			$this->logger->warning('Could not decrypt a stored secret', ['exception' => $e]);
			return '';
		}
	}
}
