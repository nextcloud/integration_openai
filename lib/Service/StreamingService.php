<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use Exception;
use OCA\OpenAi\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class StreamingService {
	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private OpenAiSettingsService $openAiSettingsService,
		private bool $isCLI,
	) {
	}

	/**
	 * @param string|null $userId
	 * @param string $endPoint
	 * @param array<string, mixed> $params
	 * @param string|null $serviceType
	 * @param bool $isUsingOpenAi
	 * @return \Generator<string>
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
				'User-Agent: ' . Application::USER_AGENT,
				'Content-Type: application/json',
				'Accept: text/event-stream',
			];
			if ($isUsingOpenAi || !$useBasicAuth) {
				if ($apiKey !== '') {
					$headers[] = 'Authorization: Bearer ' . $apiKey;
				}
			} elseif ($basicUser !== '' && $basicPassword !== '') {
				$headers[] = 'Authorization: Basic ' . base64_encode($basicUser . ':' . $basicPassword);
			}

			$contentType = '';
			$statusCode = 0;
			$retryAfter = '';
			$eventBuffer = '';
			$rawBody = '';
			$queuedPartials = [];
			$done = false;

			$ch = curl_init($url);
			if ($ch === false) {
				throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
			}
			$multiHandle = curl_multi_init();

			try {
				curl_setopt_array($ch, [
					CURLOPT_POST => true,
					CURLOPT_POSTFIELDS => $payload,
					CURLOPT_HTTPHEADER => $headers,
					CURLOPT_TIMEOUT => $timeout,
					CURLOPT_RETURNTRANSFER => false,
					CURLOPT_HEADER => false,
					CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
					CURLOPT_BUFFERSIZE => 1024,
					CURLOPT_HEADERFUNCTION => function ($curl, string $headerLine) use (&$contentType, &$statusCode, &$retryAfter): int {
						$trimmed = trim($headerLine);
						$length = strlen($headerLine);

						if ($trimmed === '') {
							return $length;
						}

						if (preg_match('/^HTTP\/\S+\s+(\d+)/', $trimmed, $matches) === 1) {
							$statusCode = (int)$matches[1];
							$contentType = '';
							$retryAfter = '';
							return $length;
						}

						$separatorPos = strpos($trimmed, ':');
						if ($separatorPos === false) {
							return $length;
						}

						$name = strtolower(trim(substr($trimmed, 0, $separatorPos)));
						$value = trim(substr($trimmed, $separatorPos + 1));
						if ($name === 'content-type') {
							$contentType = $value;
						} elseif ($name === 'retry-after') {
							$retryAfter = $value;
						}

						return $length;
					},
					CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$contentType, &$eventBuffer, &$rawBody, &$queuedPartials, &$done): int {
						if (str_starts_with(strtolower($contentType), 'text/event-stream')) {
							$queuedPartials = [...$queuedPartials, ...$this->parseSseChunk($chunk, $eventBuffer, $done)];
						} else {
							$rawBody .= $chunk;
						}

						return strlen($chunk);
					},
				]);

				curl_multi_add_handle($multiHandle, $ch);

				do {
					do {
						$multiExecResult = curl_multi_exec($multiHandle, $active);
					} while ($multiExecResult === CURLM_CALL_MULTI_PERFORM);

					while ($queuedPartials !== []) {
						yield array_shift($queuedPartials);
					}

					if ($multiExecResult !== CURLM_OK) {
						throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
					}

					if ($active) {
						$selectResult = curl_multi_select($multiHandle, 1.0);
						if ($selectResult === -1) {
							usleep(10000);
						}
					}
				} while ($active);

				while ($queuedPartials !== []) {
					yield array_shift($queuedPartials);
				}

				if (!$done && str_starts_with(strtolower($contentType), 'text/event-stream')) {
					foreach ($this->parseSseChunk('', $eventBuffer, $done, true) as $partial) {
						yield $partial;
					}
				}

				if (curl_errno($ch) !== 0) {
					throw new Exception('cURL error: ' . curl_error($ch), Http::STATUS_INTERNAL_SERVER_ERROR);
				}

				if ($statusCode === 0) {
					$statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
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

				return;
			} finally {
				curl_multi_remove_handle($multiHandle, $ch);
				curl_close($ch);
				curl_multi_close($multiHandle);
			}
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
	 * @return string[]
	 * @throws Exception
	 */
	private function parseSseEvent(string $event, bool &$done): array {
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
	 * @param bool $flush
	 * @return string[]
	 * @throws Exception
	 */
	private function parseSseChunk(string $chunk, string &$buffer, bool &$done, bool $flush = false): array {
		$buffer .= str_replace(["\r\n", "\r"], "\n", $chunk);
		$partials = [];

		while (($delimiterPos = strpos($buffer, "\n\n")) !== false) {
			$event = substr($buffer, 0, $delimiterPos);
			$buffer = substr($buffer, $delimiterPos + 2);
			$partials = [...$partials, ...$this->parseSseEvent($event, $done)];
			if ($done) {
				return $partials;
			}
		}

		if ($flush && trim($buffer) !== '') {
			$partials = [...$partials, ...$this->parseSseEvent($buffer, $done)];
			$buffer = '';
		}

		return $partials;
	}
}
