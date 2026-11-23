<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\OpenAi\Tests\Unit\Service;

use OCA\OpenAi\Service\ContentChunkParserService;
use PHPUnit\Framework\TestCase;

class ContentChunkParserServiceTest extends TestCase {
	private ContentChunkParserService $contentChunkParserService;

	protected function setUp(): void {
		$this->contentChunkParserService = new ContentChunkParserService();
	}

	public function testTextChunksAreConcatenatedAndOtherTypesSkipped(): void {
		$this->assertSame('Amsterdam. It is worth visiting.', $this->contentChunkParserService->contentChunksToText([
			['type' => 'text', 'text' => 'Amsterdam'],
			['type' => 'reference', 'reference_ids' => [1]],
			['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'Thinking']]],
			['type' => 'text', 'text' => '. It is worth visiting.'],
		]));
	}

	public function testMalformedChunksAreSkipped(): void {
		$this->assertSame('Hello', $this->contentChunkParserService->contentChunksToText([
			'not a chunk',
			['type' => 'text'],
			['type' => 'text', 'text' => 1],
			['type' => 'text', 'text' => 'Hello'],
		]));
	}

	public function testEmptyChunksReturnAnEmptyString(): void {
		$this->assertSame('', $this->contentChunkParserService->contentChunksToText([]));
	}
}
