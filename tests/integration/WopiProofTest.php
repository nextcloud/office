<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The editor publishes a proof key (the StubEditor does), so the WOPI endpoints
 * take only requests it signed. Every other test signs through static::$http;
 * these send their own headers.
 */

namespace OCA\Office\Tests\Integration;

use GuzzleHttp\Client;

class WopiProofTest extends IntegrationTestCase {
	private Client $unsigned;

	protected function setUp(): void {
		$this->unsigned = new Client(['base_uri' => self::baseUrl(), 'http_errors' => false]);
	}

	/**
	 * @return array{0: string, 1: string} token and request path
	 */
	private function tokenForNewFile(string $name): array {
		$file = $this->createTestFile($name);
		$token = $this->generateToken($file)->getToken();

		return [$token, $this->wopiFilesUrl($file->getId(), $token)];
	}

	public function testSignedRequestIsAccepted(): void {
		[$token, $path] = $this->tokenForNewFile('proof-signed.docx');

		$response = $this->unsigned->get($path, [
			'headers' => static::$editor->proofHeaders($token, self::baseUrl() . $path),
		]);

		$this->assertSame(200, $response->getStatusCode());
	}

	public function testUnsignedRequestIsRejected(): void {
		[, $path] = $this->tokenForNewFile('proof-unsigned.docx');

		$this->assertSame(403, $this->unsigned->get($path)->getStatusCode());
	}

	public function testRequestSignedWithAnotherKeyIsRejected(): void {
		[$token, $path] = $this->tokenForNewFile('proof-other-key.docx');

		$response = $this->unsigned->get($path, [
			'headers' => static::$editor->proofHeaders($token, self::baseUrl() . $path, key: StubEditor::newKey()),
		]);

		$this->assertSame(403, $response->getStatusCode());
	}

	public function testProofOlderThan20MinutesIsRejected(): void {
		[$token, $path] = $this->tokenForNewFile('proof-stale.docx');

		$response = $this->unsigned->get($path, [
			'headers' => static::$editor->proofHeaders($token, self::baseUrl() . $path, unixTime: time() - 21 * 60),
		]);

		$this->assertSame(403, $response->getStatusCode());
	}

	public function testProofForOneFileDoesNotOpenAnother(): void {
		[$token, $path] = $this->tokenForNewFile('proof-signed-for.docx');
		[$otherToken, $otherPath] = $this->tokenForNewFile('proof-replayed-to.docx');

		$response = $this->unsigned->get($otherPath, [
			'headers' => static::$editor->proofHeaders($token, self::baseUrl() . $path),
		]);

		$this->assertNotSame($token, $otherToken);
		$this->assertSame(403, $response->getStatusCode());
	}
}
