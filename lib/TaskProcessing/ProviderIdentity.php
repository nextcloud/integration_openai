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
	 * The task type ID as it appears in a provider ID: the `core:` prefix of
	 * the task types the server ships is dropped, because every provider ID
	 * already says which app it belongs to. The prefix of a task type defined
	 * by another app is kept, so that two of them cannot collide.
	 */
	public static function slugifyTaskType(string $taskTypeId): string {
		return str_starts_with($taskTypeId, 'core:') ? substr($taskTypeId, 5) : $taskTypeId;
	}
}
