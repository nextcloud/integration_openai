<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use JsonSerializable;
use OCA\OpenAi\AppInfo\Application;

/**
 * Immutable configuration of one connected OpenAI-compatible service.
 *
 * Instances are created by {@see ServicesService}, which takes care of
 * decrypting the secrets. They are handed to {@see OpenAiAPIService} to
 * address a specific endpoint and to the task processing providers to
 * describe which service they belong to.
 */
class ServiceConfig implements JsonSerializable {
	/**
	 * Value types of the writable service properties, used for validation
	 * when the admin saves a service.
	 */
	public const PROPERTY_TYPES = [
		'name' => 'string',
		'url' => 'string',
		'api_key' => 'string',
		'basic_user' => 'string',
		'basic_password' => 'string',
		'use_basic_auth' => 'boolean',
		'request_timeout' => 'integer',
		'chat_endpoint_enabled' => 'boolean',
		'use_max_completion_tokens_param' => 'boolean',
		'llm_extra_params' => 'string',
		'max_tokens' => 'integer',
		'chunk_size' => 'integer',
		'multimodal_image_enabled' => 'boolean',
		'multimodal_audio_enabled' => 'boolean',
		'multimodal_video_enabled' => 'boolean',
		'multimodal_document_enabled' => 'boolean',
		'tts_voices' => 'array',
		'default_tts_voice' => 'string',
		'default_image_size' => 'string',
		'image_request_auth' => 'boolean',
		'text_enabled' => 'boolean',
		'image_enabled' => 'boolean',
		'stt_enabled' => 'boolean',
		'tts_enabled' => 'boolean',
		'translation_enabled' => 'boolean',
		'text_models' => 'array',
		'image_models' => 'array',
		'stt_models' => 'array',
		'tts_models' => 'array',
		'quotas' => 'array',
	];

	/** Properties that are stored encrypted and never sent to the frontend */
	public const SECRET_PROPERTIES = ['api_key', 'basic_password'];

	/**
	 * @param array<int, int> $quotas
	 * @param list<string> $ttsVoices
	 * @param list<string> $textModels
	 * @param list<string> $imageModels
	 * @param list<string> $sttModels
	 * @param list<string> $ttsModels
	 */
	public function __construct(
		private string $id,
		private string $name = '',
		private string $url = '',
		private string $apiKey = '',
		private string $basicUser = '',
		private string $basicPassword = '',
		private bool $useBasicAuth = false,
		private int $requestTimeout = Application::OPENAI_DEFAULT_REQUEST_TIMEOUT,
		private bool $chatEndpointEnabled = true,
		private ?bool $useMaxCompletionTokensParam = null,
		private string $llmExtraParams = '',
		private int $maxTokens = Application::DEFAULT_MAX_NUM_OF_TOKENS,
		private int $chunkSize = Application::DEFAULT_CHUNK_SIZE,
		private bool $multimodalImageEnabled = true,
		private bool $multimodalAudioEnabled = false,
		private bool $multimodalVideoEnabled = false,
		private bool $multimodalDocumentEnabled = false,
		private array $ttsVoices = Application::DEFAULT_SPEECH_VOICES,
		private string $defaultTtsVoice = Application::DEFAULT_SPEECH_VOICE,
		private string $defaultImageSize = Application::DEFAULT_DEFAULT_IMAGE_SIZE,
		private ?bool $imageRequestAuth = null,
		private bool $textEnabled = true,
		private bool $imageEnabled = true,
		private bool $sttEnabled = true,
		private bool $ttsEnabled = true,
		private bool $translationEnabled = true,
		private array $textModels = [],
		private array $imageModels = [],
		private array $sttModels = [],
		private array $ttsModels = [],
		private array $quotas = Application::DEFAULT_QUOTAS,
	) {
	}

	/**
	 * Build a service from its stored (already decrypted) representation
	 *
	 * @param array<string, mixed> $values
	 */
	public static function fromArray(string $id, array $values): self {
		$service = new self($id);
		return $service->with($values);
	}

	/**
	 * Return a copy of this service with the given properties replaced
	 *
	 * @param array<string, mixed> $values
	 */
	public function with(array $values): self {
		$new = clone $this;
		foreach ($values as $key => $value) {
			switch ($key) {
				case 'name': $new->name = (string)$value;
					break;
				case 'url': $new->url = rtrim((string)$value, '/');
					break;
				case 'api_key': $new->apiKey = (string)$value;
					break;
				case 'basic_user': $new->basicUser = (string)$value;
					break;
				case 'basic_password': $new->basicPassword = (string)$value;
					break;
				case 'use_basic_auth': $new->useBasicAuth = (bool)$value;
					break;
				case 'request_timeout': $new->requestTimeout = max(1, (int)$value);
					break;
				case 'chat_endpoint_enabled': $new->chatEndpointEnabled = (bool)$value;
					break;
				case 'use_max_completion_tokens_param': $new->useMaxCompletionTokensParam = $value === null ? null : (bool)$value;
					break;
				case 'llm_extra_params': $new->llmExtraParams = (string)$value;
					break;
				case 'max_tokens': $new->maxTokens = max(1, (int)$value);
					break;
				case 'chunk_size': $new->chunkSize = (int)$value === 0 ? 0 : max(Application::MIN_CHUNK_SIZE, (int)$value);
					break;
				case 'multimodal_image_enabled': $new->multimodalImageEnabled = (bool)$value;
					break;
				case 'multimodal_audio_enabled': $new->multimodalAudioEnabled = (bool)$value;
					break;
				case 'multimodal_video_enabled': $new->multimodalVideoEnabled = (bool)$value;
					break;
				case 'multimodal_document_enabled': $new->multimodalDocumentEnabled = (bool)$value;
					break;
				case 'tts_voices': $new->ttsVoices = array_values(array_map('strval', (array)$value));
					break;
				case 'default_tts_voice': $new->defaultTtsVoice = (string)$value;
					break;
				case 'default_image_size': $new->defaultImageSize = (string)$value;
					break;
				case 'image_request_auth': $new->imageRequestAuth = $value === null ? null : (bool)$value;
					break;
				case 'text_enabled': $new->textEnabled = (bool)$value;
					break;
				case 'image_enabled': $new->imageEnabled = (bool)$value;
					break;
				case 'stt_enabled': $new->sttEnabled = (bool)$value;
					break;
				case 'tts_enabled': $new->ttsEnabled = (bool)$value;
					break;
				case 'translation_enabled': $new->translationEnabled = (bool)$value;
					break;
				case 'text_models': $new->textModels = self::normalizeModels($value);
					break;
				case 'image_models': $new->imageModels = self::normalizeModels($value);
					break;
				case 'stt_models': $new->sttModels = self::normalizeModels($value);
					break;
				case 'tts_models': $new->ttsModels = self::normalizeModels($value);
					break;
				case 'quotas': $new->quotas = self::normalizeQuotas($value, $new->quotas);
					break;
			}
		}
		return $new;
	}

	/**
	 * @param mixed $models
	 * @return list<string>
	 */
	private static function normalizeModels(mixed $models): array {
		if (!is_array($models)) {
			return [];
		}
		$models = array_map('strval', $models);
		$models = array_filter($models, static fn (string $model) => $model !== '');
		return array_values(array_unique($models));
	}

	/**
	 * Merge the given quotas onto the ones already set, so that a partial
	 * update does not reset the quota types it does not mention. A new service
	 * passes the defaults as the base.
	 *
	 * @param mixed $quotas
	 * @param array<int, int> $base
	 * @return array<int, int>
	 */
	private static function normalizeQuotas(mixed $quotas, array $base): array {
		$normalized = $base;
		if (!is_array($quotas)) {
			return $normalized;
		}
		foreach (array_keys(Application::DEFAULT_QUOTAS) as $type) {
			if (isset($quotas[$type])) {
				$normalized[$type] = max(0, (int)$quotas[$type]);
			}
		}
		return $normalized;
	}

	public function getId(): string {
		return $this->id;
	}

	/**
	 * The raw configured name, which may be empty
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * The name to show to users, falling back to something recognizable
	 */
	public function getDisplayName(): string {
		if ($this->name !== '') {
			return $this->name;
		}
		if ($this->isUsingOpenAi()) {
			return 'OpenAI';
		}
		$host = parse_url($this->url, PHP_URL_HOST);
		return is_string($host) && $host !== '' ? $host : 'LocalAI';
	}

	/**
	 * The raw configured URL, which may be empty (meaning: the OpenAI API)
	 */
	public function getUrl(): string {
		return $this->url;
	}

	/**
	 * The URL to actually send requests to
	 */
	public function getRequestUrl(): string {
		return $this->url === '' ? Application::OPENAI_API_BASE_URL : $this->url;
	}

	public function isUsingOpenAi(): bool {
		return $this->url === '' || $this->url === Application::OPENAI_API_BASE_URL;
	}

	public function isUsingOpenRouter(): bool {
		return str_starts_with(strtolower($this->url), 'https://openrouter.ai');
	}

	public function getApiKey(): string {
		return $this->apiKey;
	}

	public function getBasicUser(): string {
		return $this->basicUser;
	}

	public function getBasicPassword(): string {
		return $this->basicPassword;
	}

	/**
	 * The raw configured switch. To decide how a request authenticates, use
	 * {@see self::usesBasicAuth()} instead.
	 */
	public function getUseBasicAuth(): bool {
		return $this->useBasicAuth;
	}

	/**
	 * Whether requests to this service authenticate with basic auth.
	 *
	 * The OpenAI API only accepts a bearer token, so the basic auth switch is
	 * ignored for it. This is the branch {@see OpenAiAPIService::request()}
	 * takes, and therefore what decides which credentials of a user are
	 * actually used.
	 */
	public function usesBasicAuth(): bool {
		return !$this->isUsingOpenAi() && $this->useBasicAuth;
	}

	public function getRequestTimeout(): int {
		return $this->requestTimeout;
	}

	public function getChatEndpointEnabled(): bool {
		return $this->chatEndpointEnabled;
	}

	public function getUseMaxCompletionTokensParam(): bool {
		// we know OpenAI expects "max_completion_tokens", let's assume the other services don't
		return $this->useMaxCompletionTokensParam ?? $this->isUsingOpenAi();
	}

	public function getLlmExtraParams(): string {
		return $this->llmExtraParams;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getLlmExtraParamsArray(): ?array {
		if ($this->llmExtraParams === '') {
			return null;
		}
		$decoded = json_decode($this->llmExtraParams, true);
		return is_array($decoded) ? $decoded : null;
	}

	public function getMaxTokens(): int {
		return $this->maxTokens;
	}

	public function getChunkSize(): int {
		return $this->chunkSize;
	}

	public function getMultimodalImageEnabled(): bool {
		return $this->multimodalImageEnabled;
	}

	public function getMultimodalAudioEnabled(): bool {
		return $this->multimodalAudioEnabled;
	}

	public function getMultimodalVideoEnabled(): bool {
		return $this->multimodalVideoEnabled;
	}

	public function getMultimodalDocumentEnabled(): bool {
		return $this->multimodalDocumentEnabled;
	}

	/**
	 * @return list<string>
	 */
	public function getTtsVoices(): array {
		return $this->ttsVoices;
	}

	public function getDefaultTtsVoice(): string {
		return $this->defaultTtsVoice;
	}

	public function getDefaultImageSize(): string {
		return $this->defaultImageSize;
	}

	public function getImageRequestAuth(): bool {
		// OpenAI serves the generated images from an unauthenticated URL, other
		// services more often than not expect the credentials
		return $this->imageRequestAuth ?? !$this->isUsingOpenAi();
	}

	/**
	 * Whether this service offers the translation task types. Translation is
	 * part of the text modality, but has its own switch because it is often
	 * served by a dedicated instance.
	 */
	public function getTranslationEnabled(): bool {
		return $this->translationEnabled;
	}

	public function isModalityEnabled(string $modality): bool {
		return match ($modality) {
			Application::MODALITY_TEXT => $this->textEnabled,
			Application::MODALITY_IMAGE => $this->imageEnabled,
			Application::MODALITY_STT => $this->sttEnabled,
			Application::MODALITY_TTS => $this->ttsEnabled,
			default => false,
		};
	}

	/**
	 * The models the admin selected for a modality, or an empty list when the
	 * modality is switched off for this service
	 *
	 * @return list<string>
	 */
	public function getModels(string $modality): array {
		if (!$this->isModalityEnabled($modality)) {
			return [];
		}
		return match ($modality) {
			Application::MODALITY_TEXT => $this->textModels,
			Application::MODALITY_IMAGE => $this->imageModels,
			Application::MODALITY_STT => $this->sttModels,
			Application::MODALITY_TTS => $this->ttsModels,
			default => [],
		};
	}

	/**
	 * The first model selected for a modality.
	 *
	 * Used by the providers that chain two modalities (enhanced transcription,
	 * audio translation, LLM-improved image prompts) to pick the model for the
	 * step outside their own modality.
	 */
	public function getFirstModel(string $modality): ?string {
		return $this->getModels($modality)[0] ?? null;
	}

	/**
	 * @return array<int, int>
	 */
	public function getQuotas(): array {
		return $this->quotas;
	}

	public function getQuota(int $quotaType): int {
		return $this->quotas[$quotaType] ?? 0;
	}

	/**
	 * Full representation, including secrets, as stored in app config
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'url' => $this->url,
			'api_key' => $this->apiKey,
			'basic_user' => $this->basicUser,
			'basic_password' => $this->basicPassword,
			'use_basic_auth' => $this->useBasicAuth,
			'request_timeout' => $this->requestTimeout,
			'chat_endpoint_enabled' => $this->chatEndpointEnabled,
			'use_max_completion_tokens_param' => $this->useMaxCompletionTokensParam,
			'llm_extra_params' => $this->llmExtraParams,
			'max_tokens' => $this->maxTokens,
			'chunk_size' => $this->chunkSize,
			'multimodal_image_enabled' => $this->multimodalImageEnabled,
			'multimodal_audio_enabled' => $this->multimodalAudioEnabled,
			'multimodal_video_enabled' => $this->multimodalVideoEnabled,
			'multimodal_document_enabled' => $this->multimodalDocumentEnabled,
			'tts_voices' => $this->ttsVoices,
			'default_tts_voice' => $this->defaultTtsVoice,
			'default_image_size' => $this->defaultImageSize,
			'image_request_auth' => $this->imageRequestAuth,
			'text_enabled' => $this->textEnabled,
			'image_enabled' => $this->imageEnabled,
			'stt_enabled' => $this->sttEnabled,
			'tts_enabled' => $this->ttsEnabled,
			'translation_enabled' => $this->translationEnabled,
			'text_models' => $this->textModels,
			'image_models' => $this->imageModels,
			'stt_models' => $this->sttModels,
			'tts_models' => $this->ttsModels,
			'quotas' => $this->quotas,
		];
	}

	/**
	 * Representation for the personal settings: only what a user needs to know
	 * to provide their own credentials for this service
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerializeForUser(): array {
		return [
			'id' => $this->id,
			'display_name' => $this->getDisplayName(),
			'url' => $this->getRequestUrl(),
			// the effective scheme, so the user is asked for the credential
			// that requests to this service actually use
			'use_basic_auth' => $this->usesBasicAuth(),
			'is_using_openai' => $this->isUsingOpenAi(),
		];
	}

	/**
	 * Representation for the admin frontend: secrets are replaced by
	 * placeholders so they are never sent to the browser
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerializeRedacted(): array {
		$values = $this->jsonSerialize();
		$values['api_key'] = $this->apiKey === '' ? '' : Application::SECRET_PLACEHOLDER;
		$values['basic_password'] = $this->basicPassword === '' ? '' : Application::SECRET_PLACEHOLDER;
		$values['display_name'] = $this->getDisplayName();
		$values['is_using_openai'] = $this->isUsingOpenAi();
		$values['model_endpoint_url'] = $this->getRequestUrl() . '/models';
		return $values;
	}
}
