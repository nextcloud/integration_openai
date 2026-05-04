<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class StreamingService {
	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private OpenAiSettingsService $openAiSettingsService,
		private IClientService $clientService,
		private bool $isCLI,
	) {
	}

	/**
	 * @param string|null $userId
	 * @param string $endPoint
	 * @param array<string, mixed> $params
	 * @param string|null $serviceType
	 * @param bool $isUsingOpenAi
	 * @return \Generator<string, mixed, mixed, array{usage?: array<string, mixed>}>
	 * @throws Exception
	 */
	public function streamRequest(
		?string $userId,
		string $endPoint,
		array $params,
		?string $serviceType,
		bool $isUsingOpenAi,
	): \Generator {
		$retryCount = 0;

		while (true) {
			$context = $this->getRequestContext($userId, $serviceType);
			$serviceUrl = $context['serviceUrl'];
			$apiKey = $context['apiKey'];
			$basicUser = $context['basicUser'];
			$basicPassword = $context['basicPassword'];
			$useBasicAuth = $context['useBasicAuth'];
			$timeout = $context['timeout'];

			if ($serviceUrl === Application::OPENAI_API_BASE_URL && $apiKey === '') {
				throw new Exception('An API key is required for api.openai.com', Http::STATUS_UNAUTHORIZED);
			}

			$url = rtrim($serviceUrl, '/') . '/' . $endPoint;
			$payload = json_encode($params);
			if ($payload === false) {
				throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			$headers = [
				'User-Agent' => Application::USER_AGENT,
				'Content-Type' => 'application/json',
				'Accept' => 'text/event-stream',
			];
			if ($isUsingOpenAi || !$useBasicAuth) {
				if ($apiKey !== '') {
					$headers['Authorization'] = 'Bearer ' . $apiKey;
				}
			} elseif ($basicUser !== '' && $basicPassword !== '') {
				$headers['Authorization'] = 'Basic ' . base64_encode($basicUser . ':' . $basicPassword);
			}

			$contentType = '';
			$statusCode = 0;
			$retryAfter = '';
			$eventBuffer = '';
			$rawBody = '';
			$done = false;
			$usage = null;

			$response = $this->clientService->newClient()->post($url, [
				'body' => $payload,
				'headers' => $headers,
				'timeout' => $timeout,
				'stream' => true,
				'nextcloud' => [
					'allow_local_address' => !$isUsingOpenAi,
				],
			]);

			$contentType = $response->getHeader('Content-Type');
			$statusCode = $response->getStatusCode();
			$retryAfter = $response->getHeader('Retry-After');
			$body = $response->getBody();

			if (!is_resource($body)) {
				throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			while (!feof($body)) {
				$chunk = fread($body, 8192);
				if ($chunk === false) {
					throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
				}
				if ($chunk === '') {
					continue;
				}

				if (str_starts_with(strtolower($contentType), 'text/event-stream')) {
					foreach ($this->parseSseChunk($chunk, $eventBuffer, $done, $usage) as $partial) {
						yield $partial;
					}
				} else {
					$rawBody .= $chunk;
				}
			}

			if (!$done && str_starts_with(strtolower($contentType), 'text/event-stream')) {
				foreach ($this->parseSseChunk('', $eventBuffer, $done, $usage, true) as $partial) {
					yield $partial;
				}
			}

			if ($statusCode === Http::STATUS_TOO_MANY_REQUESTS && $retryCount < 3 && $this->isCLI) {
				if ($retryAfter === '' || (string)(int)$retryAfter !== $retryAfter) {
					$retryAfterTime = $retryAfter === '' ? false : strtotime($retryAfter);
					$sleep = $retryAfterTime === false ? random_int(10, 120) : max(0, $retryAfterTime - time());
				} else {
					$sleep = (int)$retryAfter;
				}
				$sleep += random_int(5, 30);
				$this->logger->warning("Rate limit exceeded, retrying in $sleep seconds", ['retry_count' => $retryCount]);
				sleep($sleep);
				$retryCount++;
				continue;
			}

			if ($statusCode >= 400) {
				$parsedResponseBody = json_decode($rawBody, true);
				throw new Exception(
					$this->l10n->t('API request error: ') . (
						$statusCode === 401
						? $this->l10n->t('Invalid API Key/Basic Auth: ')
						: ''
					) . (
						isset($parsedResponseBody['error']) && isset($parsedResponseBody['error']['message'])
						? $parsedResponseBody['error']['message']
						: 'HTTP ' . $statusCode
					),
					$statusCode,
				);
			}

			if (!str_starts_with(strtolower($contentType), 'text/event-stream')) {
				throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			return $usage === null ? [] : ['usage' => $usage];
		}
	}

	/**
	 * @param string|null $userId
	 * @param string|null $serviceType
	 * @return array{serviceUrl: string, apiKey: string, basicUser: string, basicPassword: string, useBasicAuth: bool, timeout: int}
	 */
	private function getRequestContext(?string $userId, ?string $serviceType = null): array {
		if ($serviceType === Application::SERVICE_TYPE_IMAGE && $this->openAiSettingsService->imageOverrideEnabled()) {
			return [
				'serviceUrl' => $this->openAiSettingsService->getImageServiceUrl(),
				'apiKey' => $this->openAiSettingsService->getAdminImageApiKey(),
				'basicUser' => $this->openAiSettingsService->getAdminImageBasicUser(),
				'basicPassword' => $this->openAiSettingsService->getAdminImageBasicPassword(),
				'useBasicAuth' => $this->openAiSettingsService->getAdminImageUseBasicAuth(),
				'timeout' => $this->openAiSettingsService->getImageRequestTimeout(),
			];
		}
		if ($serviceType === Application::SERVICE_TYPE_STT && $this->openAiSettingsService->sttOverrideEnabled()) {
			return [
				'serviceUrl' => $this->openAiSettingsService->getSttServiceUrl(),
				'apiKey' => $this->openAiSettingsService->getAdminSttApiKey(),
				'basicUser' => $this->openAiSettingsService->getAdminSttBasicUser(),
				'basicPassword' => $this->openAiSettingsService->getAdminSttBasicPassword(),
				'useBasicAuth' => $this->openAiSettingsService->getAdminSttUseBasicAuth(),
				'timeout' => $this->openAiSettingsService->getSttRequestTimeout(),
			];
		}
		if ($serviceType === Application::SERVICE_TYPE_TTS && $this->openAiSettingsService->ttsOverrideEnabled()) {
			return [
				'serviceUrl' => $this->openAiSettingsService->getTtsServiceUrl(),
				'apiKey' => $this->openAiSettingsService->getAdminTtsApiKey(),
				'basicUser' => $this->openAiSettingsService->getAdminTtsBasicUser(),
				'basicPassword' => $this->openAiSettingsService->getAdminTtsBasicPassword(),
				'useBasicAuth' => $this->openAiSettingsService->getAdminTtsUseBasicAuth(),
				'timeout' => $this->openAiSettingsService->getTtsRequestTimeout(),
			];
		}

		$serviceUrl = $this->openAiSettingsService->getServiceUrl();
		if ($serviceUrl === '') {
			$serviceUrl = Application::OPENAI_API_BASE_URL;
		}

		return [
			'serviceUrl' => $serviceUrl,
			'apiKey' => $this->openAiSettingsService->getUserApiKey($userId, true),
			'basicUser' => $this->openAiSettingsService->getUserBasicUser($userId, true),
			'basicPassword' => $this->openAiSettingsService->getUserBasicPassword($userId, true),
			'useBasicAuth' => $this->openAiSettingsService->getUseBasicAuth(),
			'timeout' => $this->openAiSettingsService->getRequestTimeout(),
		];
	}

	/**
	 * @param string $event
	 * @param bool $done
	 * @param array<string, mixed>|null $usage
	 * @return string[]
	 * @throws Exception
	 */
	private function parseSseEvent(string $event, bool &$done, ?array &$usage): array {
		$dataLines = [];
		foreach (explode("\n", trim($event)) as $line) {
			if ($line === '' || str_starts_with($line, ':') || !str_starts_with($line, 'data:')) {
				continue;
			}
			$dataLines[] = ltrim(substr($line, 5));
		}

		if ($dataLines === []) {
			return [];
		}

		$data = implode("\n", $dataLines);
		if ($data === '[DONE]') {
			$done = true;
			return [];
		}

		$payload = json_decode($data, true);
		if (!is_array($payload)) {
			throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if (isset($payload['usage']) && is_array($payload['usage'])) {
			$usage = $payload['usage'];
		}

		$partials = [];
		foreach ($payload['choices'] ?? [] as $choice) {
			if (isset($choice['delta']['content']) && is_string($choice['delta']['content']) && $choice['delta']['content'] !== '') {
				$partials[] = $choice['delta']['content'];
			} elseif (isset($choice['message']['content']) && is_string($choice['message']['content']) && $choice['message']['content'] !== '') {
				$partials[] = $choice['message']['content'];
			}
		}

		return $partials;
	}

	/**
	 * @param string $chunk
	 * @param string $buffer
	 * @param bool $done
	 * @param array<string, mixed>|null $usage
	 * @param bool $flush
	 * @return string[]
	 * @throws Exception
	 */
	private function parseSseChunk(string $chunk, string &$buffer, bool &$done, ?array &$usage, bool $flush = false): array {
		$buffer .= str_replace(["\r\n", "\r"], "\n", $chunk);
		$partials = [];

		while (($delimiterPos = strpos($buffer, "\n\n")) !== false) {
			$event = substr($buffer, 0, $delimiterPos);
			$buffer = substr($buffer, $delimiterPos + 2);
			$partials = [...$partials, ...$this->parseSseEvent($event, $done, $usage)];
			if ($done) {
				return $partials;
			}
		}

		if ($flush && trim($buffer) !== '') {
			$partials = [...$partials, ...$this->parseSseEvent($buffer, $done, $usage)];
			$buffer = '';
		}

		return $partials;
	}
}
