<?php

namespace OCA\OpenAi\Service;

use lsolesen\pel\PelEntryUndefined;
use lsolesen\pel\PelExif;
use lsolesen\pel\PelIfd;
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelTag;
use lsolesen\pel\PelTiff;
use OCP\ITempManager;

class WatermarkingService {
	public const COMMENT = 'Generated with Artificial Intelligence';
	public function __construct(
		private ITempManager $tempManager,
	) {
	}

	public function markImage(string $image): string {
		$text = self::COMMENT;

		$img = imagecreatefromstring($image);
		$font = 5;// built-in font 1-5
		$white = imagecolorallocate($img, 255, 255, 255);
		$black = imagecolorallocate($img, 0, 0, 0);

		$w  = imagefontwidth($font) * strlen($text);
		$h  = imagefontheight($font);
		$px = imagesx($img) - $w - 10;
		$py = imagesy($img) - $h - 10;

		// draw 1-pixel black outline by offsetting in 4 directions
		for ($dx = -1; $dx <= 1; $dx++) {
			for ($dy = -1; $dy <= 1; $dy++) {
				if ($dx || $dy) imagestring($img, $font, $px + $dx, $py + $dy,  $text, $black);
			}
		}
		imagestring($img, $font, $px, $py, $text, $white);

		$tempFile = $this->tempManager->getTemporaryFile('.jpg');
		imagejpeg($img, $tempFile);
		imagedestroy($img);

		$newImage = $this->addImageExifComment($text, $tempFile);

		return $newImage;
	}

	private function addImageExifComment(string $text, string $filename): string {
		require_once(__DIR__ . '/../../vendor/fileeye/pel/autoload.php');
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

	public function markAudio():void {

	}
}