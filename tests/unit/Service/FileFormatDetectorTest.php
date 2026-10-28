<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Tests\Unit\Service;

use OCA\OpenAi\Service\FileFormatDetector;
use PHPUnit\Framework\TestCase;

class FileFormatDetectorTest extends TestCase {
	public static function imageProvider(): array {
		return [
			'jpeg' => ["\xFF\xD8\xFF\xE0" . str_repeat("\0", 32), 'jpeg'],
			'png' => ["\x89PNG\r\n\x1A\n" . str_repeat("\0", 32), 'png'],
			'gif' => ['GIF89a' . str_repeat("\0", 32), 'gif'],
			'webp' => ["RIFF\x00\x00\x00\x00WEBPVP8 ", 'webp'],
			'unknown' => ['garbage', ''],
			'too short' => ['x', ''],
		];
	}

	/**
	 * @dataProvider imageProvider
	 */
	public function testGetImageExtension(string $data, string $expected): void {
		$this->assertSame($expected, FileFormatDetector::getImageExtension($data));
	}

	public static function audioProvider(): array {
		return [
			'mp3 with ID3 tag' => ["ID3\x04\x00" . str_repeat("\0", 32), 'mp3'],
			'mp3 MPEG-1 Layer III' => ["\xFF\xFB\x90\x44" . str_repeat("\0", 32), 'mp3'],
			'mp3 MPEG-2 Layer III' => ["\xFF\xF3\x00\x00" . str_repeat("\0", 32), 'mp3'],
			'mp3 MPEG-2.5 Layer III' => ["\xFF\xE3\x00\x00" . str_repeat("\0", 32), 'mp3'],
			'adts aac MPEG-4' => ["\xFF\xF1\x50\x80" . str_repeat("\0", 32), 'aac'],
			'adts aac MPEG-2' => ["\xFF\xF9\x50\x80" . str_repeat("\0", 32), 'aac'],
			'reserved mpeg version' => ["\xFF\xE8" . str_repeat("\0", 32), ''],
			'wav' => ["RIFF\x24\x00\x00\x00WAVEfmt ", 'wav'],
			'ogg' => ["OggS\x00\x02", 'ogg'],
			'flac' => ["fLaC\x00\x00\x00\x22", 'flac'],
			'm4a' => ["\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00", 'm4a'],
			'mp4' => ["\x00\x00\x00\x20ftypisom\x00\x00\x02\x00", 'mp4'],
			'unknown' => ['garbage', ''],
			'too short' => ['x', ''],
		];
	}

	/**
	 * @dataProvider audioProvider
	 */
	public function testGetAudioExtension(string $data, string $expected): void {
		$this->assertSame($expected, FileFormatDetector::getAudioExtension($data));
	}
}
