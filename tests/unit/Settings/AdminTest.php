<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Settings;

use OCA\Office\Service\DiscoveryService;
use OCA\Office\Service\WopiProofKeys;
use OCA\Office\Settings\Admin;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminTest extends TestCase {
	/** @return array<string, array{bool, bool, string, ?bool}> */
	public static function callerChecks(): array {
		return [
			'unsigned, no allowlist' => [true, false, '', true],
			'unsigned, allowlist set' => [true, false, '10.0.0.0/24', false],
			'signed' => [true, true, '', false],
			'discovery unreachable' => [false, false, '', null],
		];
	}

	#[DataProvider('callerChecks')]
	public function testReportsWhetherWopiCallersGoUnchecked(bool $available, bool $signs, string $allowlist, ?bool $expected): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'wopi_allowlist' ? $allowlist : $default,
		);
		$discovery = $this->createMock(DiscoveryService::class);
		$discovery->method('isAvailable')->willReturn($available);
		$discovery->method('getProofKeys')->willReturn($signs ? new WopiProofKeys('Q3Vy', 'AQAB', 'Q3Vy', 'AQAB') : null);

		$params = (new Admin($appConfig, $discovery))->getForm()->getParams();

		$this->assertSame($expected, $params['wopi_caller_unchecked']);
	}
}
