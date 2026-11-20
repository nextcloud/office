<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Integration;

use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

/**
 * Stands in for the editor server: serves a discovery document with edit actions and a
 * proof key (php -S on a free local port), and signs WOPI requests with that key as an
 * editor does. Keys and signatures use ext-openssl only, so the signing side shares no
 * code with the app's verifier; tests/fixtures/wopi-proof-euro-office.json pins the
 * signed-data layout against Euro-Office's own.
 */
final class StubEditor {
	// .NET ticks (100 ns since 0001-01-01) at the Unix epoch.
	private const int EPOCH_TICKS = 621355968000000000;

	/** @param resource $process */
	private function __construct(
		public readonly string $url,
		private \OpenSSLAsymmetricKey $key,
		private $process,
		private string $root,
	) {
	}

	public static function start(): self {
		$key = self::newKey();
		$port = self::freePort();
		$url = 'http://127.0.0.1:' . $port;

		$root = sys_get_temp_dir() . '/office-stub-editor-' . bin2hex(random_bytes(6));
		mkdir($root . '/hosting', 0700, true);
		file_put_contents($root . '/hosting/discovery', self::discovery($url, $key));

		$process = proc_open(
			[PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
			[0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
		);
		if (!is_resource($process)) {
			throw new \RuntimeException('Could not start the stub editor');
		}

		$editor = new self($url, $key, $process, $root);
		for ($attempt = 0; $attempt < 50; $attempt++) {
			$socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
			if ($socket !== false) {
				fclose($socket);
				return $editor;
			}
			usleep(100_000);
		}

		$editor->stop();
		throw new \RuntimeException('The stub editor did not start listening on port ' . $port);
	}

	public function stop(): void {
		proc_terminate($this->process);
		proc_close($this->process);
		@unlink($this->root . '/hosting/discovery');
		@rmdir($this->root . '/hosting');
		@rmdir($this->root);
	}

	public static function newKey(): \OpenSSLAsymmetricKey {
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
		if ($key === false) {
			throw new \RuntimeException('Could not generate an RSA key: ' . openssl_error_string());
		}

		return $key;
	}

	/**
	 * X-WOPI-TimeStamp and X-WOPI-Proof for a request to $url, signed with this editor's
	 * key unless another is given.
	 *
	 * @return array{X-WOPI-TimeStamp: string, X-WOPI-Proof: string}
	 */
	public function proofHeaders(string $accessToken, string $url, ?int $unixTime = null, ?\OpenSSLAsymmetricKey $key = null): array {
		$ticks = ($unixTime ?? time()) * 10_000_000 + self::EPOCH_TICKS;
		if (!openssl_sign(self::proofInput($accessToken, $url, $ticks), $signature, $key ?? $this->key, OPENSSL_ALGO_SHA256)) {
			throw new \RuntimeException('Could not sign: ' . openssl_error_string());
		}

		return [
			'X-WOPI-TimeStamp' => (string)$ticks,
			'X-WOPI-Proof' => base64_encode($signature),
		];
	}

	/**
	 * Guzzle middleware that signs every request to the WOPI endpoints, as the editor does.
	 */
	public function signingMiddleware(): callable {
		return Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
			if (!str_contains($request->getUri()->getPath(), '/apps/office/wopi/')) {
				return $request;
			}

			parse_str($request->getUri()->getQuery(), $query);
			$accessToken = is_string($query['access_token'] ?? null) ? $query['access_token'] : '';
			foreach ($this->proofHeaders($accessToken, (string)$request->getUri()) as $name => $value) {
				$request = $request->withHeader($name, $value);
			}

			return $request;
		});
	}

	/**
	 * MS-WOPI proof data: length-prefixed access token, upper-cased URL and timestamp.
	 */
	public static function proofInput(string $accessToken, string $url, int $ticks): string {
		$url = strtoupper($url);

		return pack('N', strlen($accessToken)) . $accessToken
			. pack('N', strlen($url)) . $url
			. pack('N', 8) . pack('J', $ticks);
	}

	private static function discovery(string $url, \OpenSSLAsymmetricKey $key): string {
		$details = openssl_pkey_get_details($key);
		if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
			throw new \RuntimeException('Could not read the RSA key');
		}
		$modulus = base64_encode($details['rsa']['n']);
		$exponent = base64_encode($details['rsa']['e']);
		$urlsrc = htmlspecialchars($url . '/hosting/wopi/word/edit?<wopisrc=WOPI_SOURCE&>', ENT_XML1);

		return <<<XML
			<?xml version="1.0" encoding="utf-8"?>
			<wopi-discovery>
				<net-zone name="external-http">
					<app name="application/vnd.openxmlformats-officedocument.wordprocessingml.document">
						<action name="edit" ext="docx" urlsrc="{$urlsrc}"/>
					</app>
				</net-zone>
				<proof-key value="" modulus="{$modulus}" exponent="{$exponent}" oldvalue="" oldmodulus="{$modulus}" oldexponent="{$exponent}"/>
			</wopi-discovery>
			XML;
	}

	private static function freePort(): int {
		$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		if ($server === false) {
			throw new \RuntimeException('No free port: ' . $errstr);
		}
		$name = (string)stream_socket_get_name($server, false);
		fclose($server);

		return (int)substr($name, strrpos($name, ':') + 1);
	}
}
