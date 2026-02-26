<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ITaskType;
use OCP\TaskProcessing\ShapeDescriptor;

class AudioToAudioTranslateTaskType implements ITaskType {
	public const ID = Application::APP_ID . ':audio2audio:translate';

	public function __construct(
		private IL10N $l,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l->t('Translate audio');
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		return $this->l->t('Translate the input voice');
	}

	/**
	 * @return string
	 */
	public function getId(): string {
		return self::ID;
	}

	/**
	 * @return ShapeDescriptor[]
	 */
	public function getInputShape(): array {
		return [
			'input' => new ShapeDescriptor(
				$this->l->t('Input audio'),
				$this->l->t('The audio to translate'),
				EShapeType::Audio,
			),
			'origin_language' => new ShapeDescriptor(
				$this->l->t('Origin language'),
				$this->l->t('The language of the origin audio'),
				EShapeType::Enum,
			),
			'target_language' => new ShapeDescriptor(
				$this->l->t('Target language'),
				$this->l->t('The desired language to translate the origin audio in'),
				EShapeType::Enum,
			),
		];
	}

	/**
	 * @return ShapeDescriptor[]
	 */
	public function getOutputShape(): array {
		return [
			'text_output' => new ShapeDescriptor(
				$this->l->t('Text output'),
				$this->l->t('The text translation'),
				EShapeType::Text,
			),
			'audio_output' => new ShapeDescriptor(
				$this->l->t('Audio output'),
				$this->l->t('The audio translation'),
				EShapeType::Audio,
			),
		];
	}
}
