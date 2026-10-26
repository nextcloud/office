<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Service;

use OCA\Office\Service\WopiProofKeys;
use OCA\Office\Service\WopiProofVerifier;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The fixture was signed with Euro-Office's own proof code (wopiUtils.js generateProofBuffer
 * + RSA-SHA256, 2048-bit keys), so these tests check the verifier against what the editor
 * actually sends rather than against this app's reading of the spec.
 */
final class WopiProofVerifierTest extends TestCase {
	// 2026-10-26T12:00:00Z, the fixture's X-WOPI-TimeStamp.
	private const int SIGNED_AT = 1793016000;

	/** @var array<string, mixed> */
	private array $fixture;
	private WopiProofKeys $keys;
	private int $now = self::SIGNED_AT;
	private WopiProofVerifier $verifier;

	protected function setUp(): void {
		$this->fixture = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/wopi-proof-euro-office.json'), true);
		$d = $this->fixture['discovery'];
		$this->keys = new WopiProofKeys($d['modulus'], $d['exponent'], $d['oldmodulus'], $d['oldexponent']);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->verifier = new WopiProofVerifier($time);
	}

	private function isValid(string $proof, string $proofOld = '', ?string $url = null, ?string $token = null, ?string $timestamp = null): bool {
		return $this->verifier->isValid(
			$this->keys,
			$token ?? $this->fixture['accessToken'],
			$url ?? $this->fixture['url'],
			$timestamp ?? $this->fixture['timestamp'],
			$proof,
			$proofOld,
		);
	}

	public function testAcceptsAProofFromTheCurrentKey(): void {
		$this->assertTrue($this->isValid($this->fixture['proofCurrentKey']));
	}

	public function testAcceptsAProofFromTheOldKeyDuringRotation(): void {
		$this->assertTrue($this->isValid($this->fixture['proofOldKey']));
	}

	public function testAcceptsProofOldFromTheCurrentKeyDuringRotation(): void {
		$this->assertTrue($this->isValid('', $this->fixture['proofCurrentKey']));
	}

	public function testRejectsAProofFromAnotherKey(): void {
		$this->assertFalse($this->isValid($this->fixture['proofOtherKey'], $this->fixture['proofOtherKey']));
	}

	public function testRejectsAProofForAnotherUrl(): void {
		$this->assertFalse($this->isValid($this->fixture['proofCurrentKey'], url: str_replace('/82/', '/83/', $this->fixture['url'])));
	}

	public function testRejectsAProofForAnotherToken(): void {
		$this->assertFalse($this->isValid($this->fixture['proofCurrentKey'], token: 'another-token'));
	}

	public function testRejectsAProofForAnotherTimestamp(): void {
		$this->assertFalse($this->isValid($this->fixture['proofCurrentKey'], timestamp: (string)((int)$this->fixture['timestamp'] + 1)));
	}

	public function testUrlCaseDoesNotMatterBecauseTheEditorSignsItUppercased(): void {
		$this->assertTrue($this->isValid($this->fixture['proofCurrentKey'], url: strtoupper($this->fixture['url'])));
	}

	public function testRejectsGarbageAndMissingProofs(): void {
		$this->assertFalse($this->isValid('', ''));
		$this->assertFalse($this->isValid('not base64 !!', ''));
	}

	public function testRejectsUnusableKeys(): void {
		$keys = new WopiProofKeys('!!!', 'AQAB', '', '');

		$this->assertFalse($this->verifier->isValid($keys, $this->fixture['accessToken'], $this->fixture['url'], $this->fixture['timestamp'], $this->fixture['proofCurrentKey'], ''));
	}

	public function testTimestampIsFreshForTwentyMinutes(): void {
		$this->now = self::SIGNED_AT + 20 * 60;
		$this->assertTrue($this->verifier->isFresh($this->fixture['timestamp']));

		$this->now = self::SIGNED_AT + 20 * 60 + 1;
		$this->assertFalse($this->verifier->isFresh($this->fixture['timestamp']));
	}

	public function testMissingOrMalformedTimestampIsNotFresh(): void {
		$this->assertFalse($this->verifier->isFresh(''));
		$this->assertFalse($this->verifier->isFresh('12ab'));
		$this->assertFalse($this->verifier->isFresh('99999999999999999999'));
	}
}
