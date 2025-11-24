<?php

declare(strict_types=1);

// scoper.inc.php

use Symfony\Component\Finder\Finder;

return [
	'finders' => [
		Finder::create()
			->files()
			->exclude([
				'bin',
				'bamarni',
				'nextcloud',
				'symfony',
				'psr'
			])
			->in('.'),
	],
	'patchers' => [
		static function (string $filePath, string $prefix, string $content): string {
			//
			// PHP-Parser patch conditions for file targets
			//
			if (str_contains($filePath, '/pel/')) {
				return preg_replace(
					'%([ |<{:,\'](\\\\)?)lsolesen\\\\pel%',
					'$1OCA\\\\OpenAi\\\\Vendor\\\\lsolesen\\\\pel',
					$content
				);
			}

			if (str_contains($filePath, '/getid3/')) {
				return preg_replace(
					'%\'getid3_\' .%',
					'\'OCA\\\\OpenAi\\\\Vendor\\\\getid3_\' .',
					$content
				);
			}

			return $content;
		},
	],
	'expose-global-functions' => false,
	'expose-global-classes' => false,
	'expose-global-constants' => false,
];
