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

use DateTime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Db\ImageGenerationMapper;
use OCA\OpenAi\Db\ImageUrlMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
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
	private ImageGenerationMapper $imageGenerationMapper;
	private ImageUrlMapper $imageUrlMapper;

	/**
	 * Service to make requests to OpenAI REST API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								ImageGenerationMapper $imageGenerationMapper,
								ImageUrlMapper $imageUrlMapper,
								IClientService $clientService) {
		$this->client = $clientService->newClient();
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->imageGenerationMapper = $imageGenerationMapper;
		$this->imageUrlMapper = $imageUrlMapper;
	}

	/**
	 * @return array|string[]
	 */
	public function getModels(): array {
		return $this->request('models');
	}

	/**
	 * @param string $userId
	 * @return string
	 */
	public function getUserDefaultCompletionModelId(string $userId): string {
		$adminModel = $this->config->getAppValue(Application::APP_ID, 'default_completion_model_id', Application::DEFAULT_COMPLETION_MODEL_ID) ?: Application::DEFAULT_COMPLETION_MODEL_ID;
		return $this->config->getUserValue($userId, Application::APP_ID, 'default_completion_model_id', $adminModel) ?: $adminModel;
	}

	/**
	 * @param string|null $userId
	 * @param string $prompt
	 * @param int $n
	 * @param string $model
	 * @return array|string[]
	 */
	public function createCompletion(?string $userId, string $prompt, int $n, string $model): array {
		$params = [
			'model' => $model,
			'prompt' => $prompt,
			'max_tokens' => 300,
			'n' => $n,
		];
		if ($userId !== null) {
			$params['user'] = $userId;
		}
		return $this->request('completions', $params, 'POST');
	}

	/**
	 * @param string|null $userId
	 * @param string $prompt
	 * @param int $n
	 * @param string $model
	 * @return array|string[]
	 */
	public function createChatCompletion(?string $userId, string $prompt, int $n, string $model): array {
		$params = [
			'model' => $model,
			'messages' => [['role' => 'user', 'content' => $prompt ]],
			'max_tokens' => 300,
			'n' => $n,
		];
		if ($userId !== null) {
			$params['user'] = $userId;
		}
		return $this->request('chat/completions', $params, 'POST');
	}

	/**
	 * @param string|null $userId
	 * @param string $prompt
	 * @param int $n
	 * @param string $size
	 * @return array|string[]
	 */
	public function createImage(?string $userId, string $prompt, int $n = 1, string $size = Application::DEFAULT_IMAGE_SIZE): array {
		$params = [
			'prompt' => $prompt,
			'size' => $size,
			'n' => $n,
			'response_format' => 'url',
		];
		if ($userId !== null) {
			$params['user'] = $userId;
		}
		$apiResponse = $this->request('images/generations', $params, 'POST');
		if (isset($apiResponse['error'])) {
			return $apiResponse;
		}

		if (isset($apiResponse['data']) && is_array($apiResponse['data'])) {
			$urls = array_map(static function (array $result) {
				return $result['url'] ?? null;
			}, $apiResponse['data']);
			$urls = array_filter($urls, static function (?string $url) {
				return $url !== null;
			});
			$urls = array_values($urls);
			if (!empty($urls)) {
				$hash = md5(implode('|', $urls));
				$ts = (new DateTime())->getTimestamp();
				$this->imageGenerationMapper->createImageGeneration($hash, $prompt, $ts, $urls);
				return ['hash' => $hash];
			}
		}

		return $apiResponse;
	}

	/**
	 * @param string $hash
	 * @return array|string[]
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function getGenerationInfo(string $hash): array {
		try {
			$imageGeneration = $this->imageGenerationMapper->getImageGenerationFromHash($hash);
			$imageUrls = $this->imageUrlMapper->getImageUrlsOfGeneration($imageGeneration->getId());
			$this->imageGenerationMapper->touchImageGeneration($imageGeneration->getId());
			return [
				'hash' => $hash,
				'prompt' => $imageGeneration->getPrompt(),
				'urls' => $imageUrls,
			];
		} catch (DoesNotExistException $e) {
			return [
				'error' => 'notfound',
			];
		}
	}

	/**
	 * @param string $hash
	 * @param int $urlId
	 * @return array|null
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	public function getGenerationImage(string $hash, int $urlId): ?array {
		try {
			$imageGeneration = $this->imageGenerationMapper->getImageGenerationFromHash($hash);
			$imageUrl = $this->imageUrlMapper->getImageUrlOfGeneration($imageGeneration->getId(), $urlId);
			$imageResponse = $this->client->get($imageUrl->getUrl());
			return [
				'body' => $imageResponse->getBody(),
				'headers' => $imageResponse->getHeaders(),
			];
		} catch (Exception | Throwable $e) {
			$this->logger->debug('OpenAI image request error : ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
		return null;
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
