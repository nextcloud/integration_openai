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
use OCA\OpenAi\Db\PromptMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\NotPermittedException;
use OCP\Http\Client\IClient;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Service to make requests to OpenAI REST API
 */
class OpenAiAPIService {
	private IClient $client;

	public function __construct(
		string $appName,
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private ImageGenerationMapper $imageGenerationMapper,
		private ImageUrlMapper $imageUrlMapper,
		private PromptMapper $promptMapper,
		IClientService $clientService
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * @return bool
	 */
	public function isUsingOpenAi(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'url') === '';
	}

	/**
	 * @param string $userId
	 * @return array|string[]
	 */
	public function getModels(string $userId): array {
		return $this->request($userId, 'models');
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
	 * @param string $userId
	 * @param int $type
	 * @return array
	 * @throws \OCP\DB\Exception
	 */
	public function getPromptHistory(string $userId, int $type): array {
		return $this->promptMapper->getPromptsOfUser($userId, $type);
	}

	/**
	 * @param string|null $userId
	 * @param string $prompt
	 * @param int $n
	 * @param string $model
	 * @param int $maxTokens
	 * @param bool $storePrompt
	 * @return array|string[]
	 * @throws \OCP\DB\Exception
	 */
	public function createCompletion(?string $userId, string $prompt, int $n, string $model, int $maxTokens = 1000,
									 bool $storePrompt = true): array {
		$params = [
			'model' => $model,
			'prompt' => $prompt,
			'max_tokens' => $maxTokens,
			'n' => $n,
		];
		if ($userId !== null) {
			$params['user'] = $userId;
		}
		if ($storePrompt) {
			$this->promptMapper->createPrompt(Application::PROMPT_TYPE_TEXT, $userId, $prompt);
		}
		return $this->request($userId, 'completions', $params, 'POST');
	}

	/**
	 * @param string|null $userId
	 * @param string $prompt
	 * @param int $n
	 * @param string $model
	 * @param int $maxTokens
	 * @param bool $storePrompt
	 * @return array|string[]
	 * @throws \OCP\DB\Exception
	 */
	public function createChatCompletion(?string $userId, string $prompt, int $n, string $model, int $maxTokens = 1000,
										 bool $storePrompt = true): array {
		$params = [
			'model' => $model,
			'messages' => [['role' => 'user', 'content' => $prompt ]],
			'max_tokens' => $maxTokens,
			'n' => $n,
		];
		if ($userId !== null) {
			$params['user'] = $userId;
		}
		if ($storePrompt) {
			$this->promptMapper->createPrompt(Application::PROMPT_TYPE_TEXT, $userId, $prompt);
		}
		return $this->request($userId, 'chat/completions', $params, 'POST');
	}

	/**
	 * @param string|null $userId
	 * @param string $audioBase64
	 * @param bool $translate
	 * @return array|string[]
	 */
	public function transcribeBase64Mp3(?string $userId, string $audioBase64, bool $translate = true,
										string $model = Application::DEFAULT_TRANSCRIPTION_MODEL_ID): array	{
		return $this->transcribe(
			$userId,
			base64_decode(str_replace('data:audio/mp3;base64,', '', $audioBase64)),
			$translate,
			$model
		);
	}

	/**
	 * @param string|null $userId
	 * @param File $file
	 * @param bool $translate
	 * @return string
	 * @throws LockedException
	 * @throws NotPermittedException
	 * @throws GenericFileException
	 */
	public function transcribeFile(?string $userId, File $file, bool $translate = false,
								   string $model = Application::DEFAULT_TRANSCRIPTION_MODEL_ID): string {
		$transcriptionResponse = $this->transcribe($userId, $file->getContent(), $translate, $model);
		if (!isset($transcriptionResponse['text'])) {
			throw new Exception('Error transcribing file "' . $file->getName() . '": ' . json_encode($transcriptionResponse));
		}
		return $transcriptionResponse['text'];
	}

	/**
	 * @param string|null $userId
	 * @param string $audioFileContent
	 * @param bool $translate
	 * @param string $model
	 * @return array|string[]
	 */
	public function transcribe(?string $userId, string $audioFileContent, bool $translate = true,
							   string $model = Application::DEFAULT_TRANSCRIPTION_MODEL_ID): array {
		$params = [
			'model' => $model,
			'file' => $audioFileContent,
			'response_format' => 'json',
		];
		$endpoint = $translate ? 'audio/translations' : 'audio/transcriptions';
		$contentType = 'multipart/form-data';
//		$contentType = 'application/x-www-form-urlencoded';
		return $this->request($userId, $endpoint, $params, 'POST', $contentType);
	}

	/**
	 * @param string|null $userId
	 * @param string $prompt
	 * @param int $n
	 * @param string $size
	 * @return array|string[]
	 * @throws \OCP\DB\Exception
	 */
	public function createImage(?string $userId, string $prompt, int $n = 1, string $size = Application::DEFAULT_IMAGE_SIZE): array {
		$this->config->setUserValue($userId, Application::APP_ID, 'last_image_size', $size);
		$params = [
			'prompt' => $prompt,
			'size' => $size,
			'n' => $n,
			'response_format' => 'url',
		];
		if ($userId !== null) {
			$params['user'] = $userId;
		}
		$apiResponse = $this->request($userId, 'images/generations', $params, 'POST');
		if (isset($apiResponse['error'])) {
			return $apiResponse;
		}

		if (isset($apiResponse['data']) && is_array($apiResponse['data'])) {
			$this->promptMapper->createPrompt(Application::PROMPT_TYPE_IMAGE, $userId, $prompt);
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
	 * @param string|null $userId
	 * @param string $endPoint The path to reach
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @param string|null $contentType
	 * @return array decoded request result or error
	 */
	public function request(?string $userId, string $endPoint, array $params = [], string $method = 'GET', ?string $contentType = null): array {
		try {
			$serviceUrl = $this->config->getAppValue(Application::APP_ID, 'url', Application::OPENAI_API_BASE_URL) ?: Application::OPENAI_API_BASE_URL;
			$timeout = $this->config->getAppValue(Application::APP_ID, 'request_timeout', Application::OPENAI_DEFAULT_REQUEST_TIMEOUT) ?: Application::OPENAI_DEFAULT_REQUEST_TIMEOUT;
			$timeout = (int) $timeout;

			$url = $serviceUrl . '/v1/' . $endPoint;
			$options = [
				'timeout' => $timeout,
				'headers' => [
					'User-Agent' => 'Nextcloud OpenAI integration',
				],
			];

			// an API key is mandatory when using OpenAI
			$adminApiKey = $this->config->getAppValue(Application::APP_ID, 'api_key');
			$apiKey = $userId === null
				? $adminApiKey
				: ($this->config->getUserValue($userId, Application::APP_ID, 'api_key', $adminApiKey) ?: $adminApiKey);
			if ($serviceUrl === Application::OPENAI_API_BASE_URL && $apiKey === '') {
				return ['error' => 'An API key is required for api.openai.com'];
			}
			if ($apiKey !== '') {
				$options['headers']['Authorization'] = 'Bearer ' . $apiKey;
			}

			if ($contentType === null) {
				$options['headers']['Content-Type'] = 'application/json';
			} elseif ($contentType === 'multipart/form-data') {
				// no header in this case
				// $options['headers']['Content-Type'] = $contentType;
			} else {
				$options['headers']['Content-Type'] = $contentType;
			}

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					if ($contentType === 'multipart/form-data') {
						$multipart = [];
						foreach ($params as $key => $value) {
							$part = [
								'name' => $key,
								'contents' => $value,
							];
							if ($key === 'file') {
								$part['filename'] = 'file.mp3';
							}
							$multipart[] = $part;
						}
						$options['multipart'] = $multipart;
					} else {
						$options['body'] = json_encode($params);
					}
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
				$this->logger->debug('OpenAI API error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			} else {
				$this->logger->warning('OpenAI API error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			}
			return [
				'error' => $e->getMessage(),
				'body' => $parsedResponseBody,
			];
		} catch (Exception | Throwable $e) {
			$this->logger->warning('OpenAI API error : ' . $e->getMessage(), ['exception' => $e]);
			return ['error' => $e->getMessage()];
		}
	}
}
