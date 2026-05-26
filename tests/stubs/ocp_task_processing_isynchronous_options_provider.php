<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * This is the interface that is implemented by apps that
 * implement a task processing provider
 * @since 35.0.0
 */
namespace OCP\TaskProcessing;

interface ISynchronousOptionsAwareProvider extends IProvider, ISynchronousProvider {
	public function process(
		?string $userId,
		array $input,
		callable $reportProgress,
		SynchronousProviderOptions $options = new SynchronousProviderOptions(),
	): array;
}
