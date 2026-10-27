<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCP\TaskProcessing;

/**
 * The vendored nextcloud/ocp snapshot misses the FileShaped return type
 * in the @psalm-return annotation of this deprecated interface
 *
 * @since 33.0.0
 */
interface ISynchronousWatermarkingProvider extends ISynchronousProvider {
	public function process(?string $userId, array $input, callable $reportProgress, bool $includeWatermark = true): array;
}
