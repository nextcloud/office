<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Middleware;

use OCA\Office\Controller\SettingsController;
use OCA\Office\Controller\WopiController;
use OCA\Office\Exception\WopiCallerNotAllowedException;
use OCA\Office\Middleware\WopiAllowlistMiddleware;
use OCA\Office\Service\LogThrottle;
use OCA\Office\Tests\Unit\Support\MemoryCache;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Security\Ip\IAddress;
use OCP\Security\Ip\IFactory;
use OCP\Security\Ip\IRange;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class WopiAllowlistMiddlewareTest extends TestCase {
	private string $allowlist = '';
	private string $remoteAddress = '10.0.0.5';
	private LoggerInterface&MockObject $logger;
	private WopiAllowlistMiddleware $middleware;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturnCallback(fn (): string => $this->remoteAddress);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => $key === 'wopi_allowlist' ? $this->allowlist : '');

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->middleware = new WopiAllowlistMiddleware($request, $appConfig, $this->ipFactory(), $this->logger, new LogThrottle($this->throttleCache()));
	}

	/**
	 * Stands in for core's implementation with exact-address ranges and one "/24"
	 * form, enough to exercise the middleware's own logic.
	 */
	private function ipFactory(): IFactory {
		$factory = $this->createMock(IFactory::class);
		$factory->method('addressFromString')->willReturnCallback(function (string $ip): IAddress {
			if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
				throw new \InvalidArgumentException();
			}
			$address = $this->createMock(IAddress::class);
			$address->method('__toString')->willReturn($ip);
			return $address;
		});
		$factory->method('rangeFromString')->willReturnCallback(function (string $range): IRange {
			$prefix = str_ends_with($range, '.0/24') ? substr($range, 0, -4) : null;
			if ($prefix === null && filter_var($range, FILTER_VALIDATE_IP) === false) {
				throw new \InvalidArgumentException();
			}
			$mock = $this->createMock(IRange::class);
			$mock->method('contains')->willReturnCallback(
				static fn (IAddress $a): bool => $prefix !== null ? str_starts_with((string)$a, $prefix) : (string)$a === $range,
			);
			return $mock;
		});
		return $factory;
	}

	private function check(): void {
		$this->middleware->beforeController($this->createMock(WopiController::class), 'getFile');
	}

	public function testEmptyAllowlistAllowsEveryone(): void {
		$this->check();
		$this->addToAssertionCount(1);
	}

	public function testCallerInARangeIsAllowed(): void {
		$this->allowlist = '192.168.1.10, 10.0.0.0/24';
		$this->check();
		$this->addToAssertionCount(1);
	}

	public function testCallerOutsideEveryRangeIsRejected(): void {
		$this->allowlist = '192.168.1.10 10.1.0.0/24';

		$this->expectException(WopiCallerNotAllowedException::class);
		$this->check();
	}

	public function testOnlyInvalidEntriesRejectEveryoneAndAreLogged(): void {
		$this->allowlist = 'editor.example.com';
		$this->logger->expects($this->once())->method('warning');

		$this->expectException(WopiCallerNotAllowedException::class);
		$this->check();
	}

	public function testAnInvalidEntryIsReportedOnceNotOnEveryRequest(): void {
		$this->allowlist = 'editor.example.com 10.0.0.5';
		$this->logger->expects($this->once())->method('warning');

		for ($i = 0; $i < 3; $i++) {
			$this->check();
		}
	}

	public function testUnparsableCallerAddressIsRejected(): void {
		$this->allowlist = '10.0.0.5';
		$this->remoteAddress = 'not-an-ip';

		$this->expectException(WopiCallerNotAllowedException::class);
		$this->check();
	}

	public function testOtherControllersAreNotRestricted(): void {
		$this->allowlist = '192.168.1.10';
		$this->middleware->beforeController($this->createMock(SettingsController::class), 'getAdmin');
		$this->addToAssertionCount(1);
	}

	public function testRejectionIs403(): void {
		$response = $this->middleware->afterException($this->createMock(WopiController::class), 'getFile', new WopiCallerNotAllowedException('x'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	private function throttleCache(): \OCP\ICacheFactory {
		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$factory->method('createLocal')->willReturn(new MemoryCache());

		return $factory;
	}
}
