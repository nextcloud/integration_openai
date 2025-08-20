<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Controller;

use Exception;
use OCA\OpenAi\Service\QuotaRuleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;

class QuotaRuleController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private QuotaRuleService $quotaRuleService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * POST /rule Creates a new empty rule returning the value of the rule
	 * @return DataResponse
	 */
	public function addRule(): DataResponse {
		try {
			$result = $this->quotaRuleService->addRule();
			return new DataResponse($result);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * PUT /rule
	 * @param int $id
	 * @param array $rule expects: type, amount, priority, pool, entities[]
	 * @return DataResponse
	 */
	public function updateRule(int $id, array $rule): DataResponse {
		if (!isset($rule['type']) || !is_int($rule['type'])) {
			return new DataResponse(['error' => 'Missing or invalid type'], Http::STATUS_BAD_REQUEST);
		}
		if (!isset($rule['amount']) || !is_int($rule['amount'])) {
			return new DataResponse(['error' => 'Missing or invalid amount'], Http::STATUS_BAD_REQUEST);
		}
		if (!isset($rule['priority']) || !is_int($rule['priority'])) {
			return new DataResponse(['error' => 'Missing or invalid priority'], Http::STATUS_BAD_REQUEST);
		}
		if (!isset($rule['pool']) || !is_bool($rule['pool'])) {
			return new DataResponse(['error' => 'Missing or invalid pool value'], Http::STATUS_BAD_REQUEST);
		}
		if (!isset($rule['entities']) || !is_array($rule['entities'])) {
			return new DataResponse(['error' => 'Missing or invalid entities'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$result = $this->quotaRuleService->updateRule($id, $rule);
			return new DataResponse($result);
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * DELETE /rule
	 * @param int $id
	 * @return DataResponse
	 */
	public function deleteRule(int $id): DataResponse {
		try {
			$this->quotaRuleService->deleteRule($id);
			return new DataResponse('');
		} catch (Exception $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
	/**
	 * Gets the quota usage between two dates
	 * @param int $startDate
	 * @param int $endDate
	 * @param int $type
	 * @return Http\StreamResponse|TextPlainResponse
	 */
	#[NoCSRFRequired]
	public function getQuotaUsage(int $startDate, int $endDate, int $type): Response {
		try {
			$result = $this->quotaRuleService->getQuotaUsage($startDate, $endDate, $type);
			$csv = fopen('php://memory', 'w');
			try {
				foreach ($result as $row) {
					fputcsv($csv, $row);
				}
				rewind($csv);

				$response = new Http\StreamResponse($csv, Http::STATUS_OK);
				$response->setHeaders([
					'Content-Type' => 'text/csv',
					'Content-Disposition' => 'attachment; filename="quota_usage.csv"'
				]);
				return $response;
			} catch (Exception $e) {
				return new TextPlainResponse('Failed to get quota usage:' . $e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
			}
		} catch (Exception $e) {
			return new TextPlainResponse('Failed to get quota usage:' . $e->getMessage(), Http::STATUS_NOT_FOUND);
		}
	}
}
