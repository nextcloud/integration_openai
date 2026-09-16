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

class ImageToImageTaskType implements ITaskType {
	public const ID = Application::APP_ID . ':image2image';

	public function __construct(
		private IL10N $l,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l->t('Edit image');
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		return $this->l->t('Edit an image based on a text description of the changes');
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
				$this->l->t('Input images'),
				$this->l->t('The images to edit'),
				EShapeType::ListOfImages
			),
			'prompt' => new ShapeDescriptor(
				$this->l->t('Prompt'),
				$this->l->t('Describe the changes you want to make to the image'),
				EShapeType::Text
			),
		];
	}

	/**
	 * @return ShapeDescriptor[]
	 */
	public function getOutputShape(): array {
		return [
			'output' => new ShapeDescriptor(
				$this->l->t('Output image'),
				$this->l->t('The edited image'),
				EShapeType::Image
			),
		];
	}
}
