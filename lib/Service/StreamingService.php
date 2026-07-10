<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Service;

use Exception;
use OCP\AppFramework\Http;
use OCP\IL10N;

class StreamingService {
	private const CHUNK_SIZE = 64;

	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * Parse a streamed chat response and yield assistant content fragments as they arrive.
	 *
	 * The generator return value contains the reconstructed response payload, including
	 * usage and rebuilt choices for SSE responses, or the original decoded JSON body
	 * when the upstream responded with non-streamed JSON.
	 *
	 * @param array{body?: mixed, content-type?: mixed, error?: string} $response
	 * @return \Generator<int, array{kind: string, text: string, index: int}, mixed, array<string, mixed>>
	 * @throws Exception
	 */
	public function parseStreamChatResponse(array $response): \Generator {
		if (isset($response['error']) && is_string($response['error'])) {
			throw new Exception($response['error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$contentType = strtolower((string)($response['content-type'] ?? ''));
		$body = $response['body'] ?? null;

		if (str_starts_with($contentType, 'text/event-stream')) {
			if (!is_resource($body)) {
				throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			$eventBuffer = '';
			$done = false;
			$usage = null;
			$choices = [];

			while (!feof($body)) {
				$chunk = fread($body, self::CHUNK_SIZE);
				if ($chunk === false) {
					throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
				}
				if ($chunk === '') {
					continue;
				}

				foreach ($this->parseSseChunk($chunk, $eventBuffer, $done, $usage, $choices) as $partial) {
					yield $partial;
				}
			}

			if (!$done) {
				foreach ($this->parseSseChunk('', $eventBuffer, $done, $usage, $choices, true) as $partial) {
					yield $partial;
				}
			}

			$result = [];
			if ($usage !== null) {
				$result['usage'] = $usage;
			}
			if ($choices !== []) {
				ksort($choices);
				$result['choices'] = array_values($choices);
			}
			return $result;
		}

		if (!is_array($body)) {
			throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return $body;
	}

	/**
	 * Parse one SSE event payload, update the accumulated choice state, and return any
	 * assistant text fragments that should be yielded to the caller.
	 *
	 * @param string $event
	 * @param bool $done
	 * @param array<string, mixed>|null $usage
	 * @param array<int, array<string, mixed>> $choices
	 * @return list<array{kind: string, text: string, index: int}>
	 * @throws Exception
	 */
	private function parseSseEvent(string $event, bool &$done, ?array &$usage, array &$choices): array {
		$dataLines = [];
		foreach (explode("\n", trim($event)) as $line) {
			if ($line === '' || str_starts_with($line, ':') || !str_starts_with($line, 'data:')) {
				continue;
			}
			$dataLines[] = ltrim(substr($line, 5));
		}

		if ($dataLines === []) {
			return [];
		}

		$data = implode("\n", $dataLines);
		if ($data === '[DONE]') {
			$done = true;
			return [];
		}

		$payload = json_decode($data, true);
		if (!is_array($payload)) {
			throw new Exception($this->l10n->t('Malformed API response'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if (isset($payload['usage']) && is_array($payload['usage'])) {
			$usage = $payload['usage'];
		}

		$partials = [];
		foreach ($payload['choices'] ?? [] as $choice) {
			if (!is_array($choice)) {
				continue;
			}

			$index = isset($choice['index']) && is_int($choice['index']) ? $choice['index'] : count($choices);
			if (!isset($choices[$index])) {
				$choices[$index] = [
					'index' => $index,
					'message' => [],
				];
			}

			if (isset($choice['finish_reason'])) {
				$choices[$index]['finish_reason'] = $choice['finish_reason'];
			}

			if (isset($choice['delta']['content']) && is_string($choice['delta']['content']) && $choice['delta']['content'] !== '') {
				$choices[$index]['message']['content'] = ($choices[$index]['message']['content'] ?? '') . $choice['delta']['content'];
				$partials[] = ['kind' => 'content', 'text' => $choice['delta']['content'], 'index' => $index];
			} elseif (isset($choice['message']['content']) && is_string($choice['message']['content']) && $choice['message']['content'] !== '') {
				$choices[$index]['message']['content'] = $choice['message']['content'];
				$partials[] = ['kind' => 'content', 'text' => $choice['message']['content'], 'index' => $index];
			}

			if (isset($choice['delta']['reasoning_content']) && is_string($choice['delta']['reasoning_content']) && $choice['delta']['reasoning_content'] !== '') {
				$choices[$index]['message']['reasoning_content'] = ($choices[$index]['message']['reasoning_content'] ?? '') . $choice['delta']['reasoning_content'];
				$partials[] = [
					'kind' => 'reasoning_content',
					'text' => $choice['delta']['reasoning_content'],
					'index' => $index,
				];
			} elseif (isset($choice['message']['reasoning_content']) && is_string($choice['message']['reasoning_content']) && $choice['message']['reasoning_content'] !== '') {
				$choices[$index]['message']['reasoning_content'] = $choice['message']['reasoning_content'];
				$partials[] = [
					'kind' => 'reasoning_content',
					'text' => $choice['message']['reasoning_content'],
					'index' => $index,
				];
			}

			if (isset($choice['delta']['audio']) && is_array($choice['delta']['audio'])) {
				$existingAudio = $choices[$index]['message']['audio'] ?? [];
				if (!is_array($existingAudio)) {
					$existingAudio = [];
				}
				$choices[$index]['message']['audio'] = array_merge($existingAudio, $choice['delta']['audio']);
			} elseif (isset($choice['message']['audio']) && is_array($choice['message']['audio'])) {
				$choices[$index]['message']['audio'] = $choice['message']['audio'];
			}

			if (isset($choice['message']['images']) && is_array($choice['message']['images'])) {
				$choices[$index]['message']['images'] = $choice['message']['images'];
			}
			// TODO decide if we stream the tool_calls

			if (isset($choice['delta']['tool_calls']) && is_array($choice['delta']['tool_calls'])) {
				$this->mergeToolCalls($choices[$index]['message'], $choice['delta']['tool_calls']);
			} elseif (isset($choice['message']['tool_calls']) && is_array($choice['message']['tool_calls'])) {
				$choices[$index]['message']['tool_calls'] = $choice['message']['tool_calls'];
			}
		}

		return $partials;
	}

	/**
	 * Append a raw chunk to the SSE buffer and parse all complete events currently
	 * available in that buffer.
	 *
	 * When `$flush` is true, the remaining buffer is parsed as a final event even if it
	 * is not terminated by the usual blank-line SSE delimiter.
	 *
	 * @param string $chunk
	 * @param string $buffer
	 * @param bool $done
	 * @param array<string, mixed>|null $usage
	 * @param array<int, array<string, mixed>> $choices
	 * @param bool $flush
	 * @return list<array{kind: string, text: string, index: int}>
	 * @throws Exception
	 */
	private function parseSseChunk(string $chunk, string &$buffer, bool &$done, ?array &$usage, array &$choices, bool $flush = false): array {
		$buffer .= str_replace(["\r\n", "\r"], "\n", $chunk);
		$partials = [];

		while (($delimiterPos = strpos($buffer, "\n\n")) !== false) {
			$event = substr($buffer, 0, $delimiterPos);
			$buffer = substr($buffer, $delimiterPos + 2);
			$partials = [...$partials, ...$this->parseSseEvent($event, $done, $usage, $choices)];
			if ($done) {
				return $partials;
			}
		}

		if ($flush && trim($buffer) !== '') {
			$partials = [...$partials, ...$this->parseSseEvent($buffer, $done, $usage, $choices)];
			$buffer = '';
		}

		return $partials;
	}

	/**
	 * Merge fragmented `tool_calls` deltas from multiple SSE events into the accumulated
	 * message structure for a single choice.
	 *
	 * @param array<string, mixed> $message
	 * @param array<int, array<string, mixed>> $toolCallsDelta
	 */
	private function mergeToolCalls(array &$message, array $toolCallsDelta): void {
		$toolCalls = $message['tool_calls'] ?? [];
		if (!is_array($toolCalls)) {
			$toolCalls = [];
		}

		foreach ($toolCallsDelta as $toolCallDelta) {
			if (!is_array($toolCallDelta)) {
				continue;
			}

			$index = isset($toolCallDelta['index']) && is_int($toolCallDelta['index']) ? $toolCallDelta['index'] : count($toolCalls);
			if (!isset($toolCalls[$index]) || !is_array($toolCalls[$index])) {
				$toolCalls[$index] = [];
			}

			if (isset($toolCallDelta['id']) && is_string($toolCallDelta['id'])) {
				$toolCalls[$index]['id'] = $toolCallDelta['id'];
			}
			if (isset($toolCallDelta['type']) && is_string($toolCallDelta['type'])) {
				$toolCalls[$index]['type'] = $toolCallDelta['type'];
			}
			if (isset($toolCallDelta['function']) && is_array($toolCallDelta['function'])) {
				$existingFunction = $toolCalls[$index]['function'] ?? [];
				if (!is_array($existingFunction)) {
					$existingFunction = [];
				}
				if (isset($toolCallDelta['function']['name']) && is_string($toolCallDelta['function']['name'])) {
					$existingFunction['name'] = $toolCallDelta['function']['name'];
				}
				if (isset($toolCallDelta['function']['arguments']) && is_string($toolCallDelta['function']['arguments'])) {
					$existingFunction['arguments'] = ($existingFunction['arguments'] ?? '') . $toolCallDelta['function']['arguments'];
				}
				$toolCalls[$index]['function'] = $existingFunction;
			}
		}

		ksort($toolCalls);
		$message['tool_calls'] = array_values($toolCalls);
	}
}
