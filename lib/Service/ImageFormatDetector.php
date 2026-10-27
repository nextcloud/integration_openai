<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

class ImageFormatDetector {
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
}
