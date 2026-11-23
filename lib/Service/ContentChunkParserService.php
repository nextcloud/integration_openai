<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

class ContentChunkParserService {
	/**
	 * Extract the text of typed content chunks. Only chunks with type `text` and a
	 * string `text` value contribute; `thinking`, `reference` and other types are skipped.
	 *
	 * @param array<int, mixed> $chunks
	 * @return string
	 */
	public function contentChunksToText(array $chunks): string {
		$text = '';
		foreach ($chunks as $chunk) {
			if (is_array($chunk) && ($chunk['type'] ?? null) === 'text' && isset($chunk['text']) && is_string($chunk['text'])) {
				$text .= $chunk['text'];
			}
		}
		return $text;
	}
}
