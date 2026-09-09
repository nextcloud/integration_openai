<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCA\OpenAi\Service\ChunkService;
use OCA\OpenAi\Service\OpenAiAPIService;
use OCA\OpenAi\Service\ServiceConfig;
use OCA\OpenAi\Service\ServicesService;
use OCA\OpenAi\Service\TranslateService;
use OCA\OpenAi\Service\WatermarkingService;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\TaskProcessing\IProvider;
use Psr\Log\LoggerInterface;

/**
 * Builds one task processing provider per (service, selected model, task type).
 *
 * The providers of this app depend on what the admin selected in the settings,
 * so they cannot be registered as classes and resolved by the DI container.
 * They are constructed here instead and handed to the server through
 * {@see TaskProcessingProviderListener}.
 */
class ProviderFactory {
	public function __construct(
		private ServicesService $servicesService,
		private OpenAiAPIService $openAiAPIService,
		private ChunkService $chunkService,
		private TranslateService $translateService,
		private WatermarkingService $watermarkingService,
		private IClientService $clientService,
		private IUserManager $userManager,
		private IFactory $l10nFactory,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * All providers the current configuration exposes
	 *
	 * @return IProvider[]
	 */
	public function getProviders(): array {
		$providers = [];
		foreach ($this->servicesService->getServices() as $service) {
			foreach ($service->getModels(Application::MODALITY_TEXT) as $model) {
				array_push($providers, ...$this->getTextProviders($service, $model));
			}
			foreach ($service->getModels(Application::MODALITY_IMAGE) as $model) {
				array_push($providers, ...$this->getImageProviders($service, $model));
			}
			foreach ($service->getModels(Application::MODALITY_STT) as $model) {
				array_push($providers, ...$this->getSttProviders($service, $model));
			}
			foreach ($service->getModels(Application::MODALITY_TTS) as $model) {
				array_push($providers, ...$this->getTtsProviders($service, $model));
			}
		}
		return $providers;
	}

	/**
	 * Every text task type, for one text model
	 *
	 * @return IProvider[]
	 */
	private function getTextProviders(ServiceConfig $service, string $model): array {
		$providers = [
			new TextToTextProvider($this->openAiAPIService, $this->l, $service, $model),
			new TextToTextChatProvider($this->openAiAPIService, $this->l, $service, $model),
			new TextToTextChatWithToolsProvider($this->openAiAPIService, $this->l, $service, $model),
			new SummaryProvider($this->openAiAPIService, $this->l, $this->chunkService, $service, $model),
			new HeadlineProvider($this->openAiAPIService, $this->l, $service, $model),
			new TopicsProvider($this->openAiAPIService, $this->l, $this->chunkService, $this->logger, $service, $model),
			new ContextWriteProvider($this->openAiAPIService, $this->chunkService, $this->l, $service, $model),
			new ReformulateProvider($this->openAiAPIService, $this->l, $this->chunkService, $service, $model),
			new TextToTextImproveProvider($this->openAiAPIService, $this->l, $this->chunkService, $service, $model),
			new EmojiProvider($this->openAiAPIService, $this->l, $service, $model),
			new ChangeToneProvider($this->openAiAPIService, $this->l, $this->chunkService, $service, $model),
			new ProofreadProvider($this->openAiAPIService, $this->l, $this->chunkService, $service, $model),
			new TranslateProvider($this->openAiAPIService, $this->l, $this->translateService, $service, $model),
			new MultimodalChatWithToolsProvider(
				$this->openAiAPIService, $this->l, $this->logger, $this->watermarkingService, $service, $model,
			),
		];
		if (class_exists('OCP\\TaskProcessing\\TaskTypes\\TextToTextReformatParagraphs')) {
			$providers[] = new ReformatParagraphsProvider(
				$this->openAiAPIService, $this->l, $this->chunkService, $service, $model,
			);
		}
		if ($service->getMultimodalImageEnabled()) {
			$providers[] = new ImageToTextOcrProvider($this->openAiAPIService, $this->l, $this->logger, $service, $model);
			$providers[] = new AnalyzeImagesProvider($this->openAiAPIService, $this->l, $this->logger, $service, $model);
		}
		// The integrated audio-in/audio-out chat endpoint needs the input
		// transcript, which is a separate speech-to-text request
		$sttModel = $service->getFirstModel(Application::MODALITY_STT);
		if (
			$service->getMultimodalAudioEnabled()
			&& $sttModel !== null
			&& class_exists('OCP\\TaskProcessing\\TaskTypes\\AudioToAudioChat')
		) {
			$providers[] = new AudioToAudioChatProvider(
				$this->openAiAPIService, $this->l, $this->logger, $service, $model,
				$sttModel, $service->getFirstModel(Application::MODALITY_TTS),
			);
		}
		return $providers;
	}

	/**
	 * Every image task type, for one image model
	 *
	 * @return IProvider[]
	 */
	private function getImageProviders(ServiceConfig $service, string $model): array {
		$textToImage = new TextToImageProvider(
			$this->openAiAPIService, $this->l, $this->logger, $this->clientService,
			$this->watermarkingService, $service, $model,
		);
		$providers = [$textToImage];
		// The prompt improvement needs a text model of the same service
		$textModel = $service->getFirstModel(Application::MODALITY_TEXT);
		if ($textModel !== null) {
			$providers[] = new TextToImageImprovedPromptProvider(
				$textToImage,
				new TextToTextProvider($this->openAiAPIService, $this->l, $service, $textModel),
				$this->logger, $this->l, $this->openAiAPIService, $service, $model,
			);
		}
		return $providers;
	}

	/**
	 * Every speech-to-text task type, for one transcription model
	 *
	 * @return IProvider[]
	 */
	private function getSttProviders(ServiceConfig $service, string $model): array {
		$audioToText = new AudioToTextProvider($this->openAiAPIService, $this->logger, $this->l, $service, $model);
		$providers = [$audioToText];
		if (class_exists('OCP\\TaskProcessing\\TaskTypes\\AudioToTextSubtitles')) {
			$providers[] = new AudioToTextSubtitlesProvider(
				$this->openAiAPIService, $this->logger, $this->l, $service, $model,
			);
		}

		// The following ones need a text model of the same service
		$textModel = $service->getFirstModel(Application::MODALITY_TEXT);
		if ($textModel === null) {
			return $providers;
		}
		if (class_exists('OCP\\TaskProcessing\\TaskTypes\\TextToTextReformatParagraphs')) {
			$providers[] = new AudioToTextEnhancedProvider(
				$audioToText,
				new ReformatParagraphsProvider($this->openAiAPIService, $this->l, $this->chunkService, $service, $textModel),
				$this->openAiAPIService, $this->logger, $service, $model,
			);
		}
		// ... and speech generation on top of that
		$ttsModel = $service->getFirstModel(Application::MODALITY_TTS);
		if ($ttsModel !== null) {
			$providers[] = new AudioToAudioTranslateProvider(
				$this->openAiAPIService, $this->translateService, $this->watermarkingService,
				$this->logger, $this->l10nFactory, $this->l, $this->userManager,
				$service, $model, $textModel, $ttsModel,
			);
		}
		return $providers;
	}

	/**
	 * Every text-to-speech task type, for one speech model
	 *
	 * @return IProvider[]
	 */
	private function getTtsProviders(ServiceConfig $service, string $model): array {
		return [
			new TextToSpeechProvider(
				$this->openAiAPIService, $this->l, $this->logger,
				$this->watermarkingService, $service, $model,
			),
		];
	}

}
