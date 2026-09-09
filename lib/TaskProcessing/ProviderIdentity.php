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
	/**
	 * The provider ID is built from the immutable ID of the service, so that
	 * renaming a service or changing its URL keeps the provider preferences
	 * the admin configured in the AI admin settings intact.
	 */
	protected function buildProviderId(string $taskSlug): string {
		return Application::APP_ID . '-' . $this->service->getId() . '-' . self::slugifyModel($this->model) . '-' . $taskSlug;
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
}
