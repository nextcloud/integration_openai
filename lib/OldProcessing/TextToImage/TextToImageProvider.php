<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Julien Veyssier <julien-nc@posteo.net>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\OpenAi\OldProcessing\TextToImage;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\TextToImage\IProvider;
use Psr\Log\LoggerInterface;

class TextToImageProvider implements IProvider {
	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private LoggerInterface $logger,
		private IL10N $l,
		private IClientService $clientService,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '_image_generation';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return ($this->openAiAPIService->isUsingOpenAi()
			? $this->l->t('OpenAI\'s DALL-E 2')
			: $this->openAiAPIService->getServiceName()) . ' ImageGeneration';
	}

	/**
	 * @inheritDoc
	 */
	public function generate(string $prompt, array $resources): void {
		$startTime = time();
		try {
			$apiResponse = $this->openAiAPIService->requestImageCreation($this->userId, $prompt, count($resources));
			$urls = array_map(static function (array $result) {
				return $result['url'] ?? null;
			}, $apiResponse['data']);
			$urls = array_filter($urls, static function (?string $url) {
				return $url !== null;
			});
			$urls = array_values($urls);
			if (empty($urls)) {
				$this->logger->warning('OpenAI/LocalAI\'s text to image generation failed: no image returned');
				throw new \RuntimeException('OpenAI/LocalAI\'s text to image generation failed: no image returned');
			}
			$client = $this->clientService->newClient();
			$requestOptions = $this->openAiAPIService->getImageRequestOptions($this->userId);
			// just in case $resources is not 0-based indexed, we know $urls is
			$i = 0;
			foreach ($resources as $resource) {
				if (isset($urls[$i])) {
					$url = $urls[$i];
					$imageResponse = $client->get($url, $requestOptions);
					fwrite($resource, $imageResponse->getBody());
				}
				$i++;
			}
			$endTime = time();
			$this->openAiAPIService->updateExpImgProcessingTime($endTime - $startTime);
		} catch (\Exception $e) {
			$this->logger->warning('OpenAI/LocalAI\'s text to image generation failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new \RuntimeException('OpenAI/LocalAI\'s text to image generation failed with: ' . $e->getMessage());
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpImgProcessingTime();
	}
}
