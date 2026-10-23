<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Http;

use OCP\AppFramework\Http\ContentSecurityPolicy;

final class EditorPagePolicy {
	/**
	 * CSP for the editor page: the page posts a hidden form into a named iframe
	 * on the editor server, so that origin needs both frame-src and form-action.
	 *
	 * The origin comes from the URL the browser is actually sent to (after the
	 * public_wopi_url rewrite), and is validated strictly because the value is
	 * spliced into the CSP header verbatim.
	 *
	 * The URL carries the access token, so it is kept out of the exception
	 * message and, via SensitiveParameter, out of logged stack traces.
	 *
	 * @throws \InvalidArgumentException if the URL has no usable http(s) origin.
	 */
	public static function forEditorUrl(#[\SensitiveParameter] string $editorUrl): ContentSecurityPolicy {
		$origin = self::origin($editorUrl);

		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain($origin);
		$policy->addAllowedFormActionDomain($origin);
		return $policy;
	}

	private static function origin(#[\SensitiveParameter] string $url): string {
		$parts = parse_url($url);
		$scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
		$host = is_array($parts) ? ($parts['host'] ?? '') : '';

		if (!in_array($scheme, ['http', 'https'], true)
			|| preg_match('/^(?:[a-z0-9-]+(?:\.[a-z0-9-]+)*|\[[0-9a-f:.]+\])$/i', $host) !== 1) {
			throw new \InvalidArgumentException('Editor URL has no valid http(s) origin');
		}

		return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
	}
}
