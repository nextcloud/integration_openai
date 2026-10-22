<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\OpenAi\Tests\Unit\Service;

use OCA\OpenAi\Service\StreamingService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class StreamingServiceTest extends TestCase {
	public function testEofFlushesAnIncompleteFinalEventAndClosesTheStream(): void {
		$body = fopen('php://temp', 'r+');
		$this->assertIsResource($body);
		fwrite($body, 'data: {"choices":[{"index":0,"delta":{"content":"Hello"}}]}');
		rewind($body);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$service = new StreamingService($l10n);
		$generator = $service->parseStreamChatResponse([
			'body' => $body,
			'content-type' => 'text/event-stream',
		]);

		$this->assertSame([
			['kind' => 'content', 'text' => 'Hello', 'index' => 0],
		], iterator_to_array($generator, false));
		$this->assertSame([
			'choices' => [[
				'index' => 0,
				'message' => ['content' => 'Hello'],
			]],
		], $generator->getReturn());
		$this->assertFalse(is_resource($body));
	}

	public function testDoneEventStopsReadingAnOpenStreamAndClosesIt(): void {
		$events = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"}}]}\n\n"
			. "data: {\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n"
			. "data: {\"choices\":[],\"usage\":{\"total_tokens\":7}}\n\n"
			. "data: [DONE]\n\n";
		$paddingLength = (64 - ((strlen($events) + 2) % 64)) % 64;
		$contents = str_repeat(':', $paddingLength) . "\n\n" . $events;
		$this->assertSame(0, strlen($contents) % 64);

		KeepAliveSseStream::setContents($contents);
		$this->assertTrue(stream_wrapper_register('streamingtest', KeepAliveSseStream::class));
		$body = null;

		try {
			$body = fopen('streamingtest://response', 'r');
			$this->assertIsResource($body);

			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnArgument(0);
			$service = new StreamingService($l10n);
			$generator = $service->parseStreamChatResponse([
				'body' => $body,
				'content-type' => 'text/event-stream',
			]);

			$this->assertSame([
				['kind' => 'content', 'text' => 'Hello', 'index' => 0],
			], iterator_to_array($generator, false));
			$this->assertSame([
				'usage' => ['total_tokens' => 7],
				'choices' => [[
					'index' => 0,
					'message' => ['content' => 'Hello'],
					'finish_reason' => 'stop',
				]],
			], $generator->getReturn());
			$this->assertFalse(KeepAliveSseStream::$readPastEnd);
			$this->assertTrue(KeepAliveSseStream::$closed);
			$this->assertFalse(is_resource($body));
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
			stream_wrapper_unregister('streamingtest');
		}
	}

	public function testMalformedEventClosesTheStream(): void {
		$body = fopen('php://temp', 'r+');
		$this->assertIsResource($body);
		fwrite($body, "data: not-json\n\n");
		rewind($body);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$service = new StreamingService($l10n);
		$generator = $service->parseStreamChatResponse([
			'body' => $body,
			'content-type' => 'text/event-stream',
		]);

		$exception = null;
		try {
			iterator_to_array($generator, false);
		} catch (\Exception $error) {
			$exception = $error;
		}

		$this->assertInstanceOf(\Exception::class, $exception);
		$this->assertSame('Malformed API response', $exception->getMessage());
		$this->assertFalse(is_resource($body));
	}

	public function testAbandonedGeneratorClosesTheStream(): void {
		$contents = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"}}]}\n\n";
		$paddingLength = (64 - ((strlen($contents) + 2) % 64)) % 64;
		$contents = str_repeat(':', $paddingLength) . "\n\n" . $contents;
		KeepAliveSseStream::setContents($contents);
		$this->assertTrue(stream_wrapper_register('streamingtest', KeepAliveSseStream::class));
		$body = null;

		try {
			$body = fopen('streamingtest://response', 'r');
			$this->assertIsResource($body);

			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnArgument(0);
			$service = new StreamingService($l10n);
			$generator = $service->parseStreamChatResponse([
				'body' => $body,
				'content-type' => 'text/event-stream',
			]);

			$this->assertSame(['kind' => 'content', 'text' => 'Hello', 'index' => 0], $generator->current());
			unset($generator);
			gc_collect_cycles();

			$this->assertTrue(KeepAliveSseStream::$closed);
			$this->assertFalse(is_resource($body));
		} finally {
			if (is_resource($body)) {
				fclose($body);
			}
			stream_wrapper_unregister('streamingtest');
		}
	}
}

final class KeepAliveSseStream {
	public mixed $context = null;
	public static string $contents = '';
	public static bool $closed = false;
	public static bool $readPastEnd = false;
	private int $offset = 0;

	public static function setContents(string $contents): void {
		self::$contents = $contents;
		self::$closed = false;
		self::$readPastEnd = false;
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->offset = 0;
		return true;
	}

	public function stream_read(int $count): string {
		if ($this->offset >= strlen(self::$contents)) {
			self::$readPastEnd = true;
			throw new \RuntimeException('Read attempted after the keep-alive stream ended');
		}

		$chunk = substr(self::$contents, $this->offset, $count);
		$this->offset += strlen($chunk);
		return $chunk;
	}

	public function stream_eof(): bool {
		return false;
	}

	public function stream_stat(): array|false {
		return [];
	}

	public function stream_close(): void {
		self::$closed = true;
	}
}
