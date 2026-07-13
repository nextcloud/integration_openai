<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use OCA\OpenAi\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;

class OpenAiFileService {
	private const MAX_FILE_SIZE_BYTES = 50_000_000;

	private const VALID_IMAGE_MIME_TYPES = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
	];

	// OpenAI supports wav and mp3
	// https://platform.openai.com/docs/api-reference/chat/create#chat-create-messages
	private const SUPPORTED_INPUT_AUDIO_FORMATS = [
		'audio/mp3' => 'mp3',
		'audio/mpeg' => 'mp3',
		'audio/wav' => 'wav',
		'audio/x-wav' => 'wav',
	];

	private const VALID_TEXT_MIME_TYPES = [
		'application/javascript',
		'application/typescript',
		'message/rfc822',
		'application/x-sql',
		'application/x-scala',
		'application/x-rust',
		'application/x-powershell',
		'application/x-patch',
		'application/x-php',
		'application/x-httpd-php',
		'application/x-httpd-php-source',
		'application/json',
		'application/x-bash',
		'application/x-protobuf',
		'application/x-terraform',
		'application/x-toml',
		'application/graphql',
		'application/x-graphql',
		'application/x-ndjson',
		'application/json5',
		'application/x-json5',
		'application/toml',
		'application/x-yaml',
		'application/yaml',
		'application/x-awk',
		'application/x-subrip',
		'application/csv'
	];

	public function __construct(
		private IL10N $l10n,
		private OpenAiSettingsService $openAiSettingsService,
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * Builds file content from a file ID within a given user folder.
	 *
	 * @param int $fileId The ID of the file to build content from.
	 * @param string $userId The user ID.
	 * @return array Content array suitable for OpenAI API or other handlers.
	 * @throws ProcessingException
	 * @throws UserFacingProcessingException
	 */
	public function buildFileContentFromId(int $fileId, string $userId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$file = $userFolder->getFirstNodeById($fileId);
		return $this->buildFileContentFromFile($file);
	}

	/**
	 * Builds file content from a File object.
	 *
	 * @param ?File $file The file to build content from.
	 * @return array Content array suitable for OpenAI API or other handlers.
	 * @throws ProcessingException
	 * @throws UserFacingProcessingException
	 */
	public function buildFileContentFromFile(?File $file): array {
		if (!$file instanceof File || !$file->isReadable()) {
			throw new ProcessingException('File is not readable');
		}
		// Maximum file size for openai is 50MB.
		if ($this->isUsingOpenAi() && $file->getSize() > self::MAX_FILE_SIZE_BYTES) {
			throw new UserFacingProcessingException(
				'Filesize of input files too large. Max is 50MB',
				0,
				null,
				$this->l10n->t('The size of the input file is too large. A maximum of 50MB is allowed.'),
			);
		}

		$fileType = $file->getMimeType();
		if (str_starts_with($fileType, 'image/')) {
			return $this->buildImageContent($file);
			// OpenAI only supports this for very specific models and support is not that common
		} elseif (str_starts_with($fileType, 'audio/')) {
			return $this->buildAudioContent($file);
			// OpenAI does not currently support video attachments
		} elseif (str_starts_with($fileType, 'video/')) {
			return $this->buildVideoContent($file);
		} elseif ($fileType === 'application/pdf') {
			return $this->buildDocumentContent($file);
		} else {
			return $this->buildTextContent($file);
		}
	}


	/**
	 * @return array{type: string, image_url: array{url: string}}
	 */
	private function buildImageContent(File $file): array {
		if (!$this->openAiSettingsService->getMultimodalImageEnabled()) {
			throw new UserFacingProcessingException(
				'Image attachments are disabled',
				0,
				null,
				$this->l10n->t('Image attachments are unsupported.'),
			);
		}
		$fileType = $file->getMimeType();
		if ($this->isUsingOpenAi() && !in_array($fileType, self::VALID_IMAGE_MIME_TYPES, true)) {
			throw new UserFacingProcessingException(
				'Invalid input file type for OpenAI ' . $fileType,
				0,
				null,
				$this->l10n->t('Invalid input file type "%1$s".', [$fileType]),
			);
		}
		return [
			'type' => 'image_url',
			'image_url' => [
				'url' => 'data:' . $fileType . ';base64,' . base64_encode(stream_get_contents($file->fopen('rb'))),
			],
		];
	}

	/**
	 * @return array{type: string, input_audio: array{data: string, format: string}}
	 */
	private function buildAudioContent(File $file): array {
		if (!$this->openAiSettingsService->getMultimodalAudioEnabled()) {
			throw new UserFacingProcessingException(
				'Audio attachments are disabled',
				0,
				null,
				$this->l10n->t('Audio attachments are unsupported.'),
			);
		}
		$fileType = $file->getMimeType();

		if (!array_key_exists($fileType, self::SUPPORTED_INPUT_AUDIO_FORMATS)) {
			throw new UserFacingProcessingException(
				'Invalid input file type for OpenAI ' . $fileType,
				0,
				null,
				$this->l10n->t('Invalid input file type "%1$s".', [$fileType]),
			);
		}
		$format = self::SUPPORTED_INPUT_AUDIO_FORMATS[$fileType];
		return [
			'type' => 'input_audio',
			'input_audio' => [
				'data' => base64_encode(stream_get_contents($file->fopen('rb'))),
				'format' => $format,
			],
		];
	}

	/**
	 * @return array{type: string, video_url: array{url: string}}
	 */
	private function buildVideoContent(File $file): array {
		if (!$this->openAiSettingsService->getMultimodalVideoEnabled()) {
			throw new UserFacingProcessingException(
				'Video attachments are disabled',
				0,
				null,
				$this->l10n->t('Video attachments are unsupported.'),
			);
		}
		$fileType = $file->getMimeType();
		return [
			'type' => 'video_url',
			'video_url' => [
				'url' => 'data:' . $fileType . ';base64,' . base64_encode(stream_get_contents($file->fopen('rb'))),
			],
		];
	}

	/**
	 * @return array{type: string, file: array{filename: string, file_data: string}}
	 */
	private function buildDocumentContent(File $file): array {
		if (!$this->openAiSettingsService->getMultimodalDocumentEnabled()) {
			throw new UserFacingProcessingException(
				'Document attachments are disabled',
				0,
				null,
				$this->l10n->t('Document attachments are unsupported.'),
			);
		}
		$fileType = $file->getMimeType();
		return [
			'type' => 'file',
			'file' => [
				'filename' => $file->getName(),
				'file_data' => 'data:' . $fileType . ';base64,' . base64_encode(stream_get_contents($file->fopen('rb'))),
			],
		];
	}

	/**
	 * @return array{type: string, text: string}
	 */
	private function buildTextContent(File $file): array {
		$fileType = $file->getMimeType();
		// Sanity check that this isn't a binary
		if (!str_starts_with($fileType, 'text/') && !in_array($fileType, self::VALID_TEXT_MIME_TYPES, true)) {
			throw new UserFacingProcessingException(
				'Invalid input file type: ' . $fileType,
				0,
				null,
				$this->l10n->t('Invalid input file type: "%1$s".', [$fileType]),
			);
		}
		return [
			'type' => 'text',
			'text' => 'Filename:' . $file->getName() . "\nContent:\n" . stream_get_contents($file->fopen('rb')),
		];
	}

	private function isUsingOpenAi(): bool {
		$serviceUrl = $this->openAiSettingsService->getServiceUrl();
		return $serviceUrl === '' || $serviceUrl === Application::OPENAI_API_BASE_URL;
	}
}
