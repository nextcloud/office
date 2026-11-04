<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Controller;

use OCA\Office\Controller\SettingsController;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsControllerTest extends TestCase {
	/** @return array<string, array{string}> */
	public static function routes(): array {
		return ['read' => ['getAdmin'], 'write' => ['setAdmin']];
	}

	#[DataProvider('routes')]
	public function testSettingsRoutesAreForAdminsOnly(string $method): void {
		$reflection = new \ReflectionMethod(SettingsController::class, $method);

		foreach ([NoAdminRequired::class, PublicPage::class, AuthorizedAdminSetting::class] as $opener) {
			$this->assertSame([], $reflection->getAttributes($opener), "$method must not carry " . $opener);
		}
	}
}
