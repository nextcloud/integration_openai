<?php

declare(strict_types=1);

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use Psr\Log\LoggerInterface;
use RuntimeException;

class TextToImageProvider implements ISynchronousProvider {

	public function __construct(
		private OpenAiAPIService $openAiAPIService,
		private IL10N $l,
		private LoggerInterface $logger,
		private IClientService $clientService,
		private IConfig $config,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2image';
	}

	public function getName(): string {
		return $this->openAiAPIService->isUsingOpenAi()
			? $this->l->t('OpenAI\'s DALL-E 2')
			: $this->openAiAPIService->getServiceName();
	}

	public function getTaskTypeId(): string {
		return TextToImage::ID;
	}

	public function getExpectedRuntime(): int {
		return $this->openAiAPIService->getExpTextProcessingTime();
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getInputShapeDefaults(): array {
		return [
			'numberOfImages' => 1,
		];
	}

	public function getOptionalInputShape(): array {
		return [
			'size' => new ShapeDescriptor(
				$this->l->t('Size'),
				$this->l->t('Optional. The size of the generated images. Must be in 256x256 format.'),
				EShapeType::Text
			),
			'model' => new ShapeDescriptor(
				$this->l->t('Model'),
				$this->l->t('The model used to generate the images'),
				EShapeType::Enum
			),
		];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [
			'model' => $this->openAiAPIService->getModelEnumValues($this->userId),
		];
	}

	public function getOptionalInputShapeDefaults(): array {
		$adminModel = $this->openAiAPIService->isUsingOpenAi()
			? ($this->config->getAppValue(Application::APP_ID, 'default_image_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID)
			: $this->config->getAppValue(Application::APP_ID, 'default_image_model_id');
		return [
			'model' => $adminModel,
		];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
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

		$size = Application::DEFAULT_IMAGE_SIZE;
		if (isset($input['size']) && is_string($input['size'])) {
			$size = trim($input['size']);
		}

		if (isset($input['model']) && is_string($input['model'])) {
			$model = $input['model'];
		} else {
			$model = $this->config->getAppValue(Application::APP_ID, 'default_image_model_id', Application::DEFAULT_MODEL_ID) ?: Application::DEFAULT_MODEL_ID;
		}

		try {
			$apiResponse = $this->openAiAPIService->requestImageCreation($userId, $prompt, $model, $nbImages, $size);
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
			/** @var array<string, list<numeric|string>|numeric|string> $output */
			return $output;
		} catch (\Exception $e) {
			$this->logger->warning('OpenAI/LocalAI\'s text to image generation failed with: ' . $e->getMessage(), ['exception' => $e]);
			throw new RuntimeException('OpenAI/LocalAI\'s text to image generation failed with: ' . $e->getMessage());
		}
	}
}
