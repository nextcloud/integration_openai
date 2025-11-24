<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use OCA\OpenAi\Vendor\getid3_lib;
use OCA\OpenAi\Vendor\getid3_writetags;
use OCA\OpenAi\Vendor\lsolesen\pel\PelEntryUndefined;
use OCA\OpenAi\Vendor\lsolesen\pel\PelExif;
use OCA\OpenAi\Vendor\lsolesen\pel\PelIfd;
use OCA\OpenAi\Vendor\lsolesen\pel\PelJpeg;
use OCA\OpenAi\Vendor\lsolesen\pel\PelTag;
use OCA\OpenAi\Vendor\lsolesen\pel\PelTiff;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use const OCA\OpenAi\Vendor\GETID3_INCLUDEPATH;

class WatermarkingService {
	public const COMMENT = 'Generated with Artificial Intelligence';
	public function __construct(
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	public function markImage(string $image): string {
		try {
			$text = self::COMMENT;

			$img = imagecreatefromstring($image);
			$font = 2;// built-in font 1-5
			$white = imagecolorallocate($img, 255, 255, 255);
			$black = imagecolorallocate($img, 0, 0, 0);

			$w = imagefontwidth($font) * strlen($text);
			$h = imagefontheight($font);
			$px = imagesx($img) - $w - 10;
			$py = imagesy($img) - $h - 10;

			// draw 1-pixel black outline by offsetting in 4 directions
			for ($dx = -1; $dx <= 1; $dx++) {
				for ($dy = -1; $dy <= 1; $dy++) {
					if ($dx || $dy) {
						imagestring($img, $font, $px + $dx, $py + $dy, $text, $black);
					}
				}
			}
			imagestring($img, $font, $px, $py, $text, $white);

			$tempFile = $this->tempManager->getTemporaryFile('.jpg');
			imagejpeg($img, $tempFile);
			imagedestroy($img);

			$newImage = $this->addImageExifComment($text, $tempFile);
			return $newImage;
		} catch (\Throwable $e) {
			$this->logger->warning('Could not add AI watermark to AI generated image', ['exception' => $e]);
			return $image;
		}
	}

	private function addImageExifComment(string $text, string $filename): string {
		$peljpeg = new PelJpeg($filename);
		$exif = $peljpeg->getExif();
		if (!$exif) {
			$exif = new PelExif();
			$peljpeg->setExif($exif);
		}
		$peltiff = $exif->getTiff();
		if (!$peltiff) {
			$peltiff = new PelTiff();
			$exif->setTiff($peltiff);
		}
		$ifd = $peltiff->getIfd();
		if (!$ifd) {
			$peltiff->setIfd(new PelIfd(PelIfd::IFD0));
			$ifd = $peltiff->getIfd();
		}

		$exifIfd = $ifd->getSubIfd(PelIfd::EXIF);
		if (!$exifIfd) {
			$exifIfd = new PelIfd(PelIfd::EXIF);
			$ifd->addSubIfd($exifIfd);
		}

		$comment = $exifIfd->getEntry(PelTag::USER_COMMENT);
		if (!$comment) {
			$comment = new PelEntryUndefined(PelTag::USER_COMMENT, $text);
			$exifIfd->addEntry($comment);
		} else {
			$comment->setValue($text);
		}

		return $peljpeg->getBytes();
	}

	public function markAudio(string $audio): string {
		try {
			$tempFile = $this->tempManager->getTemporaryFile('.mp3');
			file_put_contents($tempFile, $audio);

			$getID3 = new \OCA\OpenAi\Vendor\getID3;
			$getID3->setOption(['encoding' => 'UTF-8']);
			/**
			 * @psalm-suppress UndefinedConstant
			 */
			getid3_lib::IncludeDependency(GETID3_INCLUDEPATH . 'write.php', __FILE__, true);
			$tagwriter = new getid3_writetags();
			$tagwriter->filename = $tempFile;
			$tagwriter->tagformats = ['id3v2.4'];
			$tagwriter->tag_encoding = 'UTF-8';
			$tagwriter->tag_data = ['comment' => [self::COMMENT]];
			$tagwriter->WriteTags();

			$newAudio = file_get_contents($tempFile);
			if (!$newAudio) {
				throw new \RuntimeException('Could not read temporary audio file');
			}

			return $newAudio;
		} catch (\Throwable $e) {
			$this->logger->warning('Could not add AI watermark to AI generated image', ['exception' => $e]);
			return $audio;
		}
	}
}
