<?php

declare(strict_types=1);

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @template-implements ISynchronousProvider
 */
class TextToImageProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IL10N $l,
		private LoggerInterface $logger,
		private IClientService $clientService,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2image';
	}

	public function getName(): string {
		return $this->openAiAPIService->isUsingOpenAi()
			? $this->l->t('OpenAI\'s DALL-E 2 Text-To-Image')
			: $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToImage::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime();
	}

	public function getOptionalInputShape(): array {
		return [
			'size' => new ShapeDescriptor(
				$this->l->t('Size'),
				$this->l->t('Optional. The size of the generated images. Must be in 256x256 format.'),
				EShapeType::Text
			),
		];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$startTime = time();

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new RuntimeException('Invalid prompt');
		}
		$prompt = $input['input'];

		$nbImages = 1;
		if (isset($input['numberOfImages']) && is_int($input['numberOfImages'])) {
			$nbImages = $input['numberOfImages'];
		}

		try {
			$apiResponse = $this->openAiAPIService->requestImageCreation($userId, $prompt, $nbImages);
			$urls = array_map(static function (array $result) {
				return $result['url'] ?? null;
			}, $apiResponse['data']);
			$urls = array_filter($urls, static function (?string $url) {
				return $url !== null;
			});
			$urls = array_values($urls);
			if (empty($urls)) {
				$this->logger->warning('OpenAI/LocalAI\'s text to image generation failed: no image returned');
				throw new RuntimeException('OpenAI/LocalAI\'s text to image generation failed: no image returned');
			}
			$client = $this->clientService->newClient();
			$requestOptions = $this->openAiAPIService->getImageRequestOptions($userId);
			$output = ['images' => []];
			foreach ($urls as $url) {
				$imageResponse = $client->get($url, $requestOptions);
				$output['images'][] = $imageResponse->getBody();
			}
			$endTime = time();
			$this->openAiAPIService->updateExpImgProcessingTime($endTime - $startTime);
			return $output;
		} catch(\Exception $e) {
			$this->logger->warning('OpenAI/LocalAI\'s text to image generation failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new RuntimeException('OpenAI/LocalAI\'s text to image generation failed with: ' . $e->getMessage());
		}
	}
}
