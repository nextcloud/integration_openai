<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

/**
 * Helper Service to help with chunking
 */
class ChunkService {
	public function __construct(
		private OpenAiSettingsService $openAiSettingsService,
	) {
	}

	/**
	 * @param string $prompt
	 * @param bool $outputChunking If the output is about the same size as the input so output tokens matter. Ex: translate
	 * @param int|null $maxTokens The maximum number of output tokens if specified by the user
	 * @return array
	 */
	public function chunkSplitPrompt(string $prompt, bool $outputChunking = false, ?int $maxTokens = null): array {
		$chunkSize = $this->openAiSettingsService->getChunkSize();
		if ($outputChunking) {
			$maxTokens = $maxTokens ?? $this->openAiSettingsService->getMaxTokens();
			$chunkSize = min($chunkSize, $maxTokens);
		}

		// https://platform.openai.com/tokenizer
		// Rough approximation, 1 token is approximately 4 bytes for OpenAI models
		// It's safer to have a lower estimate on the max number of tokens, so consider 3 bytes per token instead of 4 (to account for some multibyte characters)
		$maxChars = $chunkSize * 3;

		if (!$chunkSize || (mb_strlen($prompt) <= $maxChars)) {
			// Chunking is disabled or prompt is short enough to be a single chunk
			return [$prompt];
		}

		// Try splitting by paragraph, match as many paragraphs as possible per chunk up to the maximum chunk size
		if (preg_match_all("/.{1,{$maxChars}}\n/su", $prompt, $prompts)) {
			return $prompts[0];
		}

		// Try splitting by sentence
		if (preg_match_all("/.{1,{$maxChars}}[!\.\?\n]/su", $prompt, $prompts)) {
			return $prompts[0];
		}

		// Try splitting by word
		if (preg_match_all("/.{1,{$maxChars}}\W/su", $prompt, $prompts)) {
			return $prompts[0];
		}

		// Split by number of characters in maximum chunk size
		return mb_str_split($prompt, $maxChars);
	}
}
