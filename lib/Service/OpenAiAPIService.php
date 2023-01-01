<?php
/**
 * Nextcloud - OpenAI
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2022
 */

namespace OCA\OpenAi\Service;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\OpenAi\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;
use Throwable;

class OpenAiAPIService {
	private LoggerInterface $logger;
	private IL10N $l10n;
	private IConfig $config;
	private IClient $client;

	/**
	 * Service to make requests to OpenAI REST API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								IClientService $clientService) {
		$this->client = $clientService->newClient();
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->config = $config;
	}

	/**
	 * @param string $prompt
	 * @return array|string[]
	 */
	public function createCompletion(string $prompt): array {
		$params = [
			'model' => 'text-davinci-003',
			'prompt' => $prompt,
			'max_tokens' => 300,
			'n' => 1,
		];
		return $this->request('completions', $params, 'POST');
	}

	/**
	 * @param string|null $userId
	 * @param string $prompt
	 * @param int $n
	 * @param string $size
	 * @return array|string[]
	 */
	public function createImage(?string $userId, string $prompt, int $n = 1, string $size = '1024x1024'): array {
		$params = [
			'prompt' => $prompt,
			'size' => $size,
			'n' => $n,
			'response_format' => 'url',
		];
		if ($userId !== null) {
			$params['user'] = $userId;
		}
		return $this->request('images/generations', $params, 'POST');
	}

	/**
	 * Make an HTTP request to the OpenAI API
	 * @param string $endPoint The path to reach
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @return array decoded request result or error
	 */
	public function request(string $endPoint, array $params = [], string $method = 'GET'): array {
		try {
			$apiKey = $this->config->getAppValue(Application::APP_ID, 'api_key');
			if ($apiKey === '') {
				return ['error' => 'No API key'];
			}

			$url = 'https://api.openai.com/v1/' . $endPoint;
			$options = [
				'headers' => [
					'User-Agent' => 'Nextcloud OpenAI integration',
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $apiKey,
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = json_encode($params);
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true) ?: [];
			}
		} catch (ClientException | ServerException $e) {
			$responseBody = $e->getResponse()->getBody();
			$parsedResponseBody = json_decode($responseBody, true);
			if ($e->getResponse()->getStatusCode() === 404) {
				$this->logger->debug('OpenAI API error : ' . $e->getMessage(), ['response_body' => $responseBody, 'app' => Application::APP_ID]);
			} else {
				$this->logger->warning('OpenAI API error : ' . $e->getMessage(), ['response_body' => $responseBody, 'app' => Application::APP_ID]);
			}
			return [
				'error' => $e->getMessage(),
				'body' => $parsedResponseBody,
			];
		} catch (Exception | Throwable $e) {
			$this->logger->warning('OpenAI API error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}
}
