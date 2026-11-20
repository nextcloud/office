<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Http;

use OCA\Office\Http\EditorPagePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EditorPagePolicyTest extends TestCase {
	/** @return array<string, array{string, string}> */
	public static function validUrls(): array {
		return [
			'default port' => ['https://office.example.com/hosting/wopi/word/edit?wopisrc=x&access_token=secret', 'https://office.example.com'],
			'explicit port' => ['http://eo:8080/hosting/wopi/word/edit', 'http://eo:8080'],
			'ipv6 host' => ['http://[::1]:9980/x', 'http://[::1]:9980'],
			'uppercase scheme' => ['HTTPS://office.example.com/x', 'https://office.example.com'],
		];
	}

	#[DataProvider('validUrls')]
	public function testAllowsEditorOriginForFrameAndFormAction(string $editorUrl, string $origin): void {
		$policy = EditorPagePolicy::forEditorUrl($editorUrl)->buildPolicy();

		$this->assertMatchesRegularExpression('#frame-src [^;]*' . preg_quote($origin, '#') . '(;| |$)#', $policy);
		$this->assertMatchesRegularExpression('#form-action [^;]*' . preg_quote($origin, '#') . '(;| |$)#', $policy);
	}

	public function testOnlyTheOriginReachesThePolicy(): void {
		$policy = EditorPagePolicy::forEditorUrl('https://office.example.com/hosting/wopi?access_token=secret')->buildPolicy();

		$this->assertStringNotContainsString('secret', $policy);
		$this->assertStringNotContainsString('/hosting', $policy);
	}

	/** @return array<string, array{string}> */
	public static function invalidUrls(): array {
		return [
			'empty' => [''],
			'relative' => ['/hosting/wopi/word/edit'],
			'non-http scheme' => ['javascript://office.example.com/x'],
			'directive separator in host' => ['https://a;b/x'],
			'whitespace in host' => ['https://a b;script-src */x'],
		];
	}

	#[DataProvider('invalidUrls')]
	public function testRejectsUrlWithoutUsableOrigin(string $editorUrl): void {
		$this->expectException(\InvalidArgumentException::class);
		EditorPagePolicy::forEditorUrl($editorUrl);
	}

	public function testRejectionKeepsTheTokenOutOfMessageAndTrace(): void {
		// Off is PHP's default and what the stock Nextcloud images run with.
		$previous = ini_set('zend.exception_ignore_args', '0');
		try {
			EditorPagePolicy::forEditorUrl('/hosting/wopi/word/edit?access_token=tok123');
			$this->fail('Expected InvalidArgumentException');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringNotContainsString('tok123', $e->getMessage());
			foreach ($e->getTrace() as $frame) {
				foreach ($frame['args'] ?? [] as $arg) {
					$this->assertFalse(is_string($arg) && str_contains($arg, 'tok123'), 'Token leaked into ' . $frame['function'] . '() trace args');
				}
			}
		} finally {
			ini_set('zend.exception_ignore_args', (string)$previous);
		}
	}
}
