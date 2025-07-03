<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenAi\TaskProcessing;

use OCA\OpenAi\AppInfo\Application;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ITaskType;
use OCP\TaskProcessing\ShapeDescriptor;

class AnalyzeImagesTaskType implements ITaskType {
	public const ID = Application::APP_ID . ':analyze-images';

	public function __construct(
		private IL10N $l,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l->t('Analyze images');
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): string {
		return $this->l->t('Ask a question about the given images.');
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
			'images' => new ShapeDescriptor(
				$this->l->t('Images'),
				$this->l->t('Images to ask a question about'),
				EShapeType::ListOfImages,
			),
			'input' => new ShapeDescriptor(
				$this->l->t('Question'),
				$this->l->t('What to ask about the image.'),
				EShapeType::Text,
			),
		];
	}

	/**
	 * @return ShapeDescriptor[]
	 */
	public function getOutputShape(): array {
		return [
			'output' => new ShapeDescriptor(
				$this->l->t('Generated response'),
				$this->l->t('The answer to the question'),
				EShapeType::Text
			),
		];
	}
}
