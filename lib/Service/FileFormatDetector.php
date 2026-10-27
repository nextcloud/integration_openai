<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

class FileFormatDetector {
	/**
	 * Detects the file extension of binary image data from its magic bytes
	 *
	 * @param string $data
	 * @return string The detected file extension, or '' if the format is unknown
	 */
	public static function getImageExtension(string $data): string {
		if (str_starts_with($data, "\xFF\xD8\xFF")) {
			return 'jpeg';
		}
		if (str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
			return 'png';
		}
		if (str_starts_with($data, 'GIF8')) {
			return 'gif';
		}
		if (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP') {
			return 'webp';
		}
		return '';
	}

	/**
	 * Detects the file extension of binary audio data from its magic bytes
	 *
	 * @param string $data
	 * @return string The detected file extension, or '' if the format is unknown
	 */
	public static function getAudioExtension(string $data): string {
		if (str_starts_with($data, 'ID3')) {
			return 'mp3';
		}
		if (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WAVE') {
			return 'wav';
		}
		if (str_starts_with($data, 'OggS')) {
			return 'ogg';
		}
		if (str_starts_with($data, 'fLaC')) {
			return 'flac';
		}
		if (strlen($data) >= 8 && substr($data, 4, 4) === 'ftyp') {
			// MP4 container, brand distinguishes Apple audio files
			return substr($data, 8, 4) === 'M4A ' ? 'm4a' : 'mp4';
		}
		if (strlen($data) >= 2 && ord($data[0]) === 0xFF && (ord($data[1]) & 0xE0) === 0xE0) {
			$b2 = ord($data[1]);
			// MPEG version 0b01 is reserved, neither valid MPEG audio nor ADTS
			if (($b2 & 0x18) === 0x08) {
				return '';
			}
			// zero layer bits identify ADTS AAC, never valid MPEG audio
			if (($b2 & 0x06) === 0) {
				return 'aac';
			}
			return 'mp3';
		}
		return '';
	}
}
