<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCP\TaskProcessing;

/**
 * @since 35.0.0
 */
interface ISynchronousProgressiveProvider extends ISynchronousProvider {
	public function process(?string $userId, array $input, callable $reportProgress, ?callable $reportOutput = null, bool $preferStreaming = true): array;
}
