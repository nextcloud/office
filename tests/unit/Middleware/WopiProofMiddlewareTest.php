<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Middleware;

use OCA\Office\Controller\SettingsController;
use OCA\Office\Controller\WopiController;
use OCA\Office\Exception\WopiProofException;
use OCA\Office\Middleware\WopiProofMiddleware;
use OCA\Office\Service\DiscoveryService;
use OCA\Office\Service\LogThrottle;
use OCA\Office\Service\WopiProofKeys;
use OCA\Office\Service\WopiProofVerifier;
use OCA\Office\Tests\Unit\Support\MemoryCache;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs the real verifier against a request signed by Euro-Office's own proof code
 * (tests/fixtures/wopi-proof-euro-office.json). The editor called Nextcloud at
 * http://nextcloud-internal:8080 (callback_url) while Nextcloud's own absolute URL is
 * https://cloud.example.com.
 */
final class WopiProofMiddlewareTest extends TestCase {
	private const string NC_BASE = 'https://cloud.example.com';
	private const string CALLBACK_BASE = 'http://nextcloud-internal:8080';

	/** @var array<string, mixed> */
	private array $fixture;
	/** @var array<string, string> */
	private array $headers = [];
	private ?WopiProofKeys $keys;
	private int $now = 1793016000 + 60;
	private DiscoveryService&MockObject $discoveryService;
	private LoggerInterface&MockObject $logger;
	private WopiProofMiddleware $middleware;

	protected function setUp(): void {
		$this->fixture = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/wopi-proof-euro-office.json'), true);
		$d = $this->fixture['discovery'];
		$this->keys = new WopiProofKeys($d['modulus'], $d['exponent'], $d['oldmodulus'], $d['oldexponent']);
		$this->headers = [
			'X-WOPI-Proof' => $this->fixture['proofCurrentKey'],
			'X-WOPI-ProofOld' => $this->fixture['proofOldKey'],
			'X-WOPI-TimeStamp' => $this->fixture['timestamp'],
		];

		$requestUri = substr($this->fixture['url'], strlen(self::CALLBACK_BASE));
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => $this->headers[$name] ?? '');
		$request->method('getRequestUri')->willReturn($requestUri);
		$request->method('getParam')->willReturnCallback(fn (string $name, mixed $default = null): mixed => match ($name) {
			'access_token' => $this->fixture['accessToken'],
			'fileId' => '82',
			default => $default,
		});

		$this->discoveryService = $this->createMock(DiscoveryService::class);
		$this->discoveryService->method('getProofKeys')->willReturnCallback(fn (): ?WopiProofKeys => $this->keys);
		$this->discoveryService->method('editorReachableUrl')->willReturnCallback(
			static fn (string $url): string => str_replace(self::NC_BASE, self::CALLBACK_BASE, $url),
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => self::NC_BASE . $url);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->middleware = new WopiProofMiddleware($request, $this->discoveryService, new WopiProofVerifier($time), $urlGenerator, $this->logger, new LogThrottle($this->throttleCache()));
	}

	private function wopiController(): WopiController {
		return $this->createMock(WopiController::class);
	}

	public function testAcceptsTheEditorsSignatureOverTheUrlItCalled(): void {
		$this->middleware->beforeController($this->wopiController(), 'getFile');
		$this->addToAssertionCount(1);
	}

	public function testRejectsAForgedProof(): void {
		$this->headers['X-WOPI-Proof'] = $this->fixture['proofOtherKey'];
		$this->headers['X-WOPI-ProofOld'] = $this->fixture['proofOtherKey'];

		$this->expectException(WopiProofException::class);
		$this->middleware->beforeController($this->wopiController(), 'getFile');
	}

	public function testRejectsAnUnsignedRequestWhenTheEditorPublishesKeys(): void {
		$this->headers = ['X-WOPI-TimeStamp' => $this->fixture['timestamp']];

		$this->expectException(WopiProofException::class);
		$this->middleware->beforeController($this->wopiController(), 'getFile');
	}

	public function testRejectsAReplayedRequestAfterTwentyMinutes(): void {
		// The genuine signed request, replayed 25 minutes later.
		$this->now = 1793016000 + 25 * 60;

		$this->expectException(WopiProofException::class);
		$this->middleware->beforeController($this->wopiController(), 'getFile');
	}

	public function testLetsRequestsThroughWhenTheEditorDoesNotSign(): void {
		$this->keys = null;
		$this->headers = [];
		$this->discoveryService->expects($this->never())->method('refreshProofKeys');

		$this->middleware->beforeController($this->wopiController(), 'getFile');
	}

	public function testRefreshesDiscoveryWhenAProofArrivesWithoutACachedKey(): void {
		$keys = $this->keys;
		$this->keys = null;
		$this->discoveryService->expects($this->once())->method('refreshProofKeys')->willReturn($keys);

		$this->middleware->beforeController($this->wopiController(), 'getFile');
	}

	public function testIgnoresOtherControllers(): void {
		$this->headers = [];
		$this->discoveryService->expects($this->never())->method('getProofKeys');

		$this->middleware->beforeController($this->createMock(SettingsController::class), 'getAdmin');
	}

	public function testRejectionIs403WithoutTheTokenInTheContext(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			$this->anything(),
			$this->callback(fn (array $context): bool => !str_contains(json_encode($context), $this->fixture['accessToken'])),
		);

		$response = $this->middleware->afterException($this->wopiController(), 'getFile', new WopiProofException('Invalid X-WOPI-Proof'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testRepeatedRejectionsAreNotEachAWarning(): void {
		// Core adds the request URL, access token included, to every log line.
		$this->logger->expects($this->once())->method('warning');
		$this->logger->expects($this->exactly(2))->method('debug');

		for ($i = 0; $i < 3; $i++) {
			$this->middleware->afterException($this->wopiController(), 'getFile', new WopiProofException('Invalid X-WOPI-Proof'));
		}
	}

	public function testPicksUpKeysTheEditorRegenerated(): void {
		// Cached keys from before the editor's container was recreated with a new key.
		$d = $this->fixture['discovery'];
		$current = $this->keys;
		$this->keys = new WopiProofKeys($d['oldmodulus'], $d['oldexponent'], $d['oldmodulus'], $d['oldexponent']);
		$this->headers['X-WOPI-ProofOld'] = '';
		$this->discoveryService->expects($this->once())->method('refreshProofKeys')->willReturn($current);

		$this->middleware->beforeController($this->wopiController(), 'getFile');
	}

	public function testRefreshThatFindsTheSameKeysStillRejects(): void {
		$this->headers['X-WOPI-Proof'] = $this->fixture['proofOtherKey'];
		$this->headers['X-WOPI-ProofOld'] = '';
		$keys = $this->keys;
		$this->discoveryService->method('refreshProofKeys')->willReturn($keys);

		$this->expectException(WopiProofException::class);
		$this->middleware->beforeController($this->wopiController(), 'getFile');
	}

	public function testOtherExceptionsPassThrough(): void {
		$this->expectException(\RuntimeException::class);
		$this->middleware->afterException($this->wopiController(), 'getFile', new \RuntimeException('boom'));
	}

	private function throttleCache(): \OCP\ICacheFactory {
		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$factory->method('createLocal')->willReturn(new MemoryCache());

		return $factory;
	}
}
