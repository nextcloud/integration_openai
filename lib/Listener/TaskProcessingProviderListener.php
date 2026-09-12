<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Listener;

use OCA\OpenAi\TaskProcessing\ProviderFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\GetTaskProcessingProvidersEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands the providers built from the admin's service and model selection to
 * the server.
 *
 * @template-implements IEventListener<GetTaskProcessingProvidersEvent>
 */
class TaskProcessingProviderListener implements IEventListener {
	public function __construct(
		private ProviderFactory $providerFactory,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof GetTaskProcessingProvidersEvent) {
			return;
		}
		try {
			foreach ($this->providerFactory->getProviders() as $provider) {
				$event->addProvider($provider);
			}
		} catch (Throwable $e) {
			$this->logger->error('Could not build the OpenAI/LocalAI task processing providers', ['exception' => $e]);
		}
	}
}
