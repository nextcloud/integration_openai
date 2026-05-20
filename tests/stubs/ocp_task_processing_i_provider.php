<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCP\TaskProcessing;

/**
 * This is the interface that is implemented by apps that
 * implement a task processing provider
 * @since 30.0.0
 */
interface IProvider {
	public function getId(): string;
	public function getName(): string;
	public function getTaskTypeId(): string;
	public function getExpectedRuntime(): int;
	/** @return array<string, ShapeDescriptor> */
	public function getOptionalInputShape(): array;
	/** @return array<string, ShapeDescriptor> */
	public function getOptionalOutputShape(): array;
	/** @return array */
	public function getInputShapeEnumValues(): array;
	/** @return array */
	public function getInputShapeDefaults(): array;
	/** @return array */
	public function getOptionalInputShapeEnumValues(): array;
	/** @return array */
	public function getOptionalInputShapeDefaults(): array;
	/** @return array */
	public function getOutputShapeEnumValues(): array;
	/** @return array */
	public function getOptionalOutputShapeEnumValues(): array;
}
