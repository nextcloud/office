<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The stub editor's signatures are only worth testing against if it signs what a real
 * editor signs: check its signed data against a request signed by Euro-Office's own code.
 */
class StubEditorTest extends TestCase {
	public function testSignsTheSameDataAsEuroOffice(): void {
		$fixture = json_decode((string)file_get_contents(__DIR__ . '/../fixtures/wopi-proof-euro-office.json'), true);
		$data = StubEditor::proofInput($fixture['accessToken'], $fixture['url'], (int)$fixture['timestamp']);
		$key = self::publicKeyPem($fixture['discovery']['modulus'], $fixture['discovery']['exponent']);

		$this->assertSame(1, openssl_verify($data, base64_decode($fixture['proofCurrentKey']), $key, OPENSSL_ALGO_SHA256));
		$this->assertSame(0, openssl_verify($data, base64_decode($fixture['proofOtherKey']), $key, OPENSSL_ALGO_SHA256));
	}

	/**
	 * ext-openssl can't build a public key from modulus and exponent, so encode the
	 * SubjectPublicKeyInfo (RFC 8017 A.1.1) by hand.
	 */
	private static function publicKeyPem(string $modulus, string $exponent): string {
		$integer = static function (string $bytes): string {
			$bytes = ltrim($bytes, "\0");
			if (ord($bytes[0]) & 0x80) {
				$bytes = "\0" . $bytes;
			}
			return "\x02" . self::derLength(strlen($bytes)) . $bytes;
		};
		$rsaKey = $integer(base64_decode($modulus)) . $integer(base64_decode($exponent));
		$rsaKey = "\x30" . self::derLength(strlen($rsaKey)) . $rsaKey;
		$bitString = "\x00" . $rsaKey;
		// SEQUENCE { OID rsaEncryption, NULL }
		$algorithm = hex2bin('300d06092a864886f70d0101010500');
		$spki = $algorithm . "\x03" . self::derLength(strlen($bitString)) . $bitString;
		$spki = "\x30" . self::derLength(strlen($spki)) . $spki;

		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}

	private static function derLength(int $length): string {
		if ($length < 0x80) {
			return chr($length);
		}
		$bytes = ltrim(pack('N', $length), "\0");
		return chr(0x80 | strlen($bytes)) . $bytes;
	}
}
