<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Hooks;

/**
 * Stub for Psalm: nextcloud/ocp IRootFolder still extends this private interface.
 */
interface Emitter {
	/**
	 * @param string $scope
	 * @param string $method
	 * @param callable $callback
	 * @return void
	 */
	public function listen($scope, $method, callable $callback);

	/**
	 * @param string|null $scope
	 * @param string|null $method
	 * @param callable|null $callback
	 * @return void
	 */
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
