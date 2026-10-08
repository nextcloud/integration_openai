<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Tests\Unit\Service;

use OCA\OpenAi\Service\ServiceConfig;
use Test\TestCase;

class ServiceConfigTest extends TestCase {
	public function expandHeaderValueProvider(): array {
		return [
			'a value without the variable' => ['acme', '42', 'acme'],
			'the variable alone' => ['{$conversation_id}', '42', '42'],
			'the variable in a sentence' => ['conv-{$conversation_id}-v2', '42', 'conv-42-v2'],
			'the variable twice' => ['{$conversation_id}/{$conversation_id}', '42', '42/42'],
			'a request without a conversation' => ['{$conversation_id}', null, null],
			'an empty conversation ID' => ['{$conversation_id}', '', null],
			'a mistyped variable travels literally' => ['{$Conversation_ID}', '42', '{$Conversation_ID}'],
			'a conversation ID smuggling line breaks' => ['{$conversation_id}', "42\r\nX-Evil: yes", null],
			'a conversation ID smuggling control characters' => ['{$conversation_id}', "42\x01", null],
			'a conversation ID with a tab' => ['{$conversation_id}', "42\x09id", "42\x09id"],
		];
	}

	/**
	 * @dataProvider expandHeaderValueProvider
	 */
	public function testExpandHeaderValue(string $value, ?string $conversationId, ?string $expected): void {
		$this->assertSame($expected, ServiceConfig::expandHeaderValue($value, $conversationId));
	}

	public function testStoredServiceWithoutExtraHeadersDefaultsToNone(): void {
		// a service stored before extra headers existed
		$service = ServiceConfig::fromArray('s1', ['url' => 'https://example.com/v1']);
		$this->assertSame([], $service->getExtraHeaders());
	}

	public function testExtraHeadersAreNormalizedOnStorage(): void {
		$service = ServiceConfig::fromArray('s1', [
			'extra_headers' => [
				['name' => '  X-Tenant  ', 'value' => ' acme '],
				['name' => '', 'value' => 'dropped with its name'],
				['name' => 'X-Empty-Value', 'value' => ''],
			],
		]);

		$this->assertSame([
			['name' => 'X-Tenant', 'value' => 'acme'],
			['name' => 'X-Empty-Value', 'value' => ''],
		], $service->getExtraHeaders());

		// what is normalized is also what the next read from storage gets back
		$reread = ServiceConfig::fromArray('s1', $service->jsonSerialize());
		$this->assertSame($service->getExtraHeaders(), $reread->getExtraHeaders());
	}
}
