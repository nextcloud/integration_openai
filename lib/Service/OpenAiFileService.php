<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use Imagick;
use OCA\OpenAi\AppInfo\Application;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use OCP\TaskProcessing\IManager as ITaskProcessingManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
		private LoggerInterface $logger,
		private ITaskProcessingManager $taskProcessingManager,
	) {
	}

	/**
	 * Builds file content from a file ID within a given user folder.
	 *
	 * @param int $fileId The ID of the file to build content from.
	 * @param string $userId The user ID.
	 * @param ?int $taskId The ID of the task
	 * @return list<array<string, mixed>> Content parts suitable for OpenAI chat message content.
	 * @throws ProcessingException
	 * @throws UserFacingProcessingException
	 */
	public function buildFileContentFromId(int $fileId, string $userId, ?int $taskId): array {
		$file = null;
		if ($taskId !== null) {
			$task = $this->taskProcessingManager->getUserTask($taskId, $userId);
			$files = $this->taskProcessingManager->extractFileIdsFromTask($task);

			if (array_search($fileId, $files, true) === false) {
				throw new ProcessingException('File does not exist');
			}
			$file = $this->rootFolder->getFirstNodeById($fileId);

			if ($file === null) {
				$file = $this->rootFolder->getFirstNodeByIdInPath($fileId, '/' . $this->rootFolder->getAppDataDirectoryName() . '/');
			}
		} else {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$file = $userFolder->getFirstNodeById($fileId);
		}
		return $this->buildFileContentFromFile($file);
	}

	/**
	 * Builds file content from a File object.
	 *
	 * @param ?File $file The file to build content from.
	 * @return list<array<string, mixed>> Content parts suitable for OpenAI chat message content.
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
		// Backup incase the file does not have an extension
		if ($fileType === 'application/octet-stream') {
			$fileType = mime_content_type($file->fopen('rb'));
		}
		if (str_starts_with($fileType, 'image/')) {
			return $this->buildImageContent($file, $fileType);
			// OpenAI only supports this for very specific models and support is not that common
		} elseif (str_starts_with($fileType, 'audio/')) {
			return $this->buildAudioContent($file, $fileType);
			// OpenAI does not currently support video attachments
		} elseif (str_starts_with($fileType, 'video/')) {
			return $this->buildVideoContent($file, $fileType);
		} elseif ($fileType === 'application/pdf') {
			return $this->buildDocumentContent($file, $fileType);
		} else {
			return $this->buildTextContent($file, $fileType);
		}
	}

	/**
	 * @return list<array{type: string, image_url: array{url: string}}>
	 */
	private function buildImageContent(File $file, string $fileType): array {
		if (!$this->openAiSettingsService->getMultimodalImageEnabled()) {
			throw new UserFacingProcessingException(
				'Image attachments are disabled',
				0,
				null,
				$this->l10n->t('Image attachments are unsupported.'),
			);
		}
		if ($this->isUsingOpenAi() && !in_array($fileType, self::VALID_IMAGE_MIME_TYPES, true)) {
			throw new UserFacingProcessingException(
				'Invalid input file type for OpenAI ' . $fileType,
				0,
				null,
				$this->l10n->t('Invalid input file type "%1$s".', [$fileType]),
			);
		}
		return [[
			'type' => 'image_url',
			'image_url' => [
				'url' => 'data:' . $fileType . ';base64,' . base64_encode(stream_get_contents($file->fopen('rb'))),
			],
		]];
	}

	/**
	 * @return list<array{type: string, input_audio: array{data: string, format: string}}>
	 */
	private function buildAudioContent(File $file, string $fileType): array {
		if (!$this->openAiSettingsService->getMultimodalAudioEnabled()) {
			throw new UserFacingProcessingException(
				'Audio attachments are disabled',
				0,
				null,
				$this->l10n->t('Audio attachments are unsupported.'),
			);
		}

		if (!array_key_exists($fileType, self::SUPPORTED_INPUT_AUDIO_FORMATS)) {
			throw new UserFacingProcessingException(
				'Invalid input file type for OpenAI ' . $fileType,
				0,
				null,
				$this->l10n->t('Invalid input file type "%1$s".', [$fileType]),
			);
		}
		$format = self::SUPPORTED_INPUT_AUDIO_FORMATS[$fileType];
		return [[
			'type' => 'input_audio',
			'input_audio' => [
				'data' => base64_encode(stream_get_contents($file->fopen('rb'))),
				'format' => $format,
			],
		]];
	}

	/**
	 * @return list<array{type: string, video_url: array{url: string}}>
	 */
	private function buildVideoContent(File $file, string $fileType): array {
		if (!$this->openAiSettingsService->getMultimodalVideoEnabled()) {
			throw new UserFacingProcessingException(
				'Video attachments are disabled',
				0,
				null,
				$this->l10n->t('Video attachments are unsupported.'),
			);
		}
		return [[
			'type' => 'video_url',
			'video_url' => [
				'url' => 'data:' . $fileType . ';base64,' . base64_encode(stream_get_contents($file->fopen('rb'))),
			],
		]];
	}

	/**
	 * @return list<array{type: string, file: array{filename: string, file_data: string}}|array{type: string, image_url: array{url: string}}>
	 */
	private function buildDocumentContent(File $file, string $fileType): array {
		if (!$this->openAiSettingsService->getMultimodalDocumentEnabled()) {
			// Fallback to image if documents are not supported
			if ($this->openAiSettingsService->getMultimodalImageEnabled()) {
				return $this->buildImageFromFile($file);
			}
			throw new UserFacingProcessingException(
				'Document attachments are disabled',
				0,
				null,
				$this->l10n->t('Document attachments are unsupported.'),
			);
		}
		return [[
			'type' => 'file',
			'file' => [
				'filename' => $file->getName(),
				'file_data' => 'data:' . $fileType . ';base64,' . base64_encode(stream_get_contents($file->fopen('rb'))),
			],
		]];
	}

	/**
	 * @return list<array{type: string, text: string}>
	 */
	private function buildTextContent(File $file, string $fileType): array {
		// Sanity check that this isn't a binary
		if (!str_starts_with($fileType, 'text/') && !in_array($fileType, self::VALID_TEXT_MIME_TYPES, true)) {
			throw new UserFacingProcessingException(
				'Invalid input file type: ' . $fileType,
				0,
				null,
				$this->l10n->t('Invalid input file type: "%1$s".', [$fileType]),
			);
		}
		return [[
			'type' => 'text',
			'text' => 'Filename:' . $file->getName() . "\nContent:\n" . stream_get_contents($file->fopen('rb')),
		]];
	}

	private function isUsingOpenAi(): bool {
		$serviceUrl = $this->openAiSettingsService->getServiceUrl();
		return $serviceUrl === '' || $serviceUrl === Application::OPENAI_API_BASE_URL;
	}

	/**
	 * @return list<array{type: string, image_url: array{url: string}}>
	 */
	private function buildImageFromFile(File $file): array {
		if (!extension_loaded('imagick')) {
			throw new RuntimeException('Imagick extension not available can not process PDF');
		}
		if (empty(Imagick::queryFormats('PDF'))) {
			throw new RuntimeException('Imagick has no PDF support (Ghostscript missing or blocked by policy.xml)');
		}
		$this->logger->debug('Building image from PDF file: {file}', ['file' => $file->getPath()]);

		$pdfContent = $file->getContent();

		// pingImageBlob avoids rasterizing every page into the pixel cache
		$probe = new Imagick();
		try {
			$probe->pingImageBlob($pdfContent);
			$pageCount = $probe->getNumberImages();
		} finally {
			$probe->clear();
			$probe->destroy();
		}

		// Limit pages to avoid overwhelming Imagick memory and the API
		$pages = min(10, $pageCount);
		$images = [];

		$document = new Imagick();
		try {
			// Keep resolution low 72 is still readable just very pixelated
			$document->setResolution(72, 72);
			$document->readImageBlob($pdfContent);

			for ($i = 0; $i < $pages; $i++) {
				$document->setIteratorIndex($i);
				$page = $document->getImage();
				$flat = null;
				try {
					$page->setBackgroundColor('white');
					$flat = $page->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
					$flat->setImageFormat('jpeg');
					$flat->setImageCompressionQuality(85);
					$images[] = [
						'type' => 'image_url',
						'image_url' => [
							'url' => 'data:image/jpeg;base64,' . base64_encode($flat->getImageBlob()),
						],
					];
				} finally {
					$page->clear();
					$page->destroy();
					if ($flat instanceof Imagick) {
						$flat->clear();
						$flat->destroy();
					}
				}
			}
		} finally {
			$document->clear();
			$document->destroy();
		}

		return $images;
	}
}
