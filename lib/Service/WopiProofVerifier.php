<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Math\BigInteger;

/**
 * Verifies MS-WOPI proof headers: the editor signs (access token, request URL, timestamp)
 * with its private key, and publishes the public keys in discovery.
 */
final class WopiProofVerifier {
	// .NET ticks (100 ns since 0001-01-01) at the Unix epoch.
	private const int EPOCH_TICKS = 621355968000000000;
	private const int MAX_AGE_SECONDS = 20 * 60;

	public function __construct(
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * phpseclib is bundled in vendor/; a checkout without composer install lacks it.
	 */
	public function isAvailable(): bool {
		return class_exists(PublicKeyLoader::class);
	}

	/**
	 * X-WOPI-TimeStamp must be no older than 20 minutes (MS-WOPI), so a captured
	 * request can't be replayed later.
	 */
	public function isFresh(string $timestamp): bool {
		if (!ctype_digit($timestamp) || strlen($timestamp) > 19) {
			return false;
		}

		$unixSeconds = intdiv((int)$timestamp - self::EPOCH_TICKS, 10_000_000);
		return $unixSeconds >= $this->timeFactory->getTime() - self::MAX_AGE_SECONDS;
	}

	/**
	 * Valid if X-WOPI-Proof verifies with the current or the old key, or X-WOPI-ProofOld
	 * with the current key: the combinations MS-WOPI allows across a key rotation.
	 */
	public function isValid(
		WopiProofKeys $keys,
		#[\SensitiveParameter]
		string $accessToken,
		#[\SensitiveParameter]
		string $url,
		string $timestamp,
		string $proof,
		string $proofOld,
	): bool {
		$signed = $this->signedData($accessToken, $url, $timestamp);
		$current = $this->publicKey($keys->modulus, $keys->exponent);
		$old = $this->publicKey($keys->oldModulus, $keys->oldExponent);

		return $this->verify($current, $signed, $proof)
			|| $this->verify($old, $signed, $proof)
			|| $this->verify($current, $signed, $proofOld);
	}

	private function signedData(string $accessToken, string $url, string $timestamp): string {
		$upperUrl = mb_strtoupper($url, 'UTF-8');

		return pack('N', strlen($accessToken)) . $accessToken
			. pack('N', strlen($upperUrl)) . $upperUrl
			. pack('N', 8) . pack('J', (int)$timestamp);
	}

	private function publicKey(string $modulus, string $exponent): ?RSA\PublicKey {
		$n = base64_decode($modulus, true);
		$e = base64_decode($exponent, true);
		if ($n === false || $e === false || $n === '' || $e === '') {
			return null;
		}

		try {
			$key = PublicKeyLoader::loadPublicKey([
				'n' => new BigInteger($n, 256),
				'e' => new BigInteger($e, 256),
			]);
		} catch (\Throwable) {
			return null;
		}

		if (!$key instanceof RSA\PublicKey) {
			return null;
		}

		// withHash() and withPadding() return a configured clone but declare no return type.
		/** @var RSA\PublicKey $key */
		$key = $key->withHash('sha256');
		/** @var RSA\PublicKey $key */
		$key = $key->withPadding(RSA::SIGNATURE_PKCS1);
		return $key;
	}

	private function verify(?RSA\PublicKey $key, string $signed, string $proof): bool {
		$signature = base64_decode($proof, true);
		if ($key === null || $signature === false || $signature === '') {
			return false;
		}

		try {
			return $key->verify($signed, $signature);
		} catch (\Throwable) {
			return false;
		}
	}
}
