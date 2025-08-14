<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\Notification;

use InvalidArgumentException;
use OCA\OpenAi\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;

use OCP\Notification\INotifier;

class Notifier implements INotifier {

	public function __construct(
		private IFactory $factory,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->factory->get(Application::APP_ID)->t('OpenAI Integration');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			// Not my app => throw
			throw new InvalidArgumentException();
		}
		if ($notification->getSubject() !== 'quota_exceeded') {
			// Not a valid subject => throw
			throw new InvalidArgumentException();
		}

		$l = $this->factory->get(Application::APP_ID, $languageCode);

		$params = $notification->getSubjectParameters();

		$subject = $l->t('Quota exceeded');
		$content = '';
		switch ($params['type']) {
			case Application::QUOTA_TYPE_TEXT:
				$content = $l->t('Text generation quota exceeded');
				break;
			case Application::QUOTA_TYPE_IMAGE:
				$content = $l->t('Image generation quota exceeded');
				break;
			case Application::QUOTA_TYPE_TRANSCRIPTION:
				$content = $l->t('Audio transcription quota exceeded');
				break;
			case Application::QUOTA_TYPE_SPEECH:
				$content = $l->t('Speech generation quota exceeded');
				break;
		}

		$link = $this->url->getWebroot() . '/settings/user/ai';
		$iconUrl = $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));

		$notification
			->setParsedSubject($subject)
			->setParsedMessage($content)
			->setLink($link)
			->setIcon($iconUrl);

		$actionLabel = $params['actionLabel'] ?? $l->t('View quota');
		$action = $notification->createAction();
		$action->setLabel($actionLabel)
			->setParsedLabel($actionLabel)
			->setLink($notification->getLink(), IAction::TYPE_WEB)
			->setPrimary(true);

		$notification->addParsedAction($action);

		return $notification;
	}
}
