<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;

/**
 * Identity of a provider that exposes one model of one connected service.
 *
 * Requires the using class to have a `$service` and a `$model` property.
 */
trait ProviderIdentity {
	abstract public function getTaskTypeId(): string;

	/**
	 * The provider ID is built from the immutable ID of the service, so that
	 * renaming a service or changing its URL keeps the provider preferences
	 * the admin configured in the AI admin settings intact.
	 *
	 * It ends in the ID of the task type the provider serves, so that the ID of
	 * every provider of this app can be derived from the service, the model and
	 * the task type alone, without looking any of them up. The two providers
	 * that wrap another one append a suffix to its ID, because a task type can
	 * be served in more than one way.
	 */
	protected function buildProviderId(): string {
		return Application::APP_ID
			. '-' . $this->service->getId()
			. '-' . self::slugifyModel($this->model)
			. '-' . self::slugifyTaskType($this->getTaskTypeId());
	}

	/**
	 * Providers are named after the model they use, so the admin can tell them
	 * apart in the AI admin settings
	 */
	protected function buildProviderName(): string {
		return $this->model . ' (' . $this->service->getDisplayName() . ')';
	}

	/**
	 * Model names can contain characters that don't belong in an ID (OpenRouter
	 * model names contain slashes, for example)
	 */
	public static function slugifyModel(string $model): string {
		return preg_replace('/[^A-Za-z0-9._-]/', '_', $model) ?? $model;
	}

	/**
	 * Formats the task type ID for inclusion in a provider ID.
	 * The `core:` prefix (for server-shipped task types) and this app's prefix are removed,
	 * since the provider ID already includes the app context. Prefixes from other apps are retained
	 * to avoid ID collisions in case of similarly named task types across apps.
	 */
	public static function slugifyTaskType(string $taskTypeId): string {
		if (str_starts_with($taskTypeId, Application::APP_ID . ':')) {
			return substr($taskTypeId, strlen(Application::APP_ID . ':'));
		}
		if (str_starts_with($taskTypeId, 'core:')) {
			return substr($taskTypeId, 5);
		}
		return $taskTypeId;
	}
}
