<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCP\TaskProcessing;

/**
 * @since 30.0.0
 */
interface ISynchronousProvider extends IProvider {
	public function process(?string $userId, array $input, callable $reportProgress): array;
}
