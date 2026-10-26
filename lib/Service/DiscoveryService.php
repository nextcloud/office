<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Service;

use OCA\Office\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

class DiscoveryService {
	private const CACHE_KEY = 'discovery';
	private const CACHE_TTL = 3600;
	// get() runs while rendering every Files page (file actions), so an unreachable editor
	// server must not be retried, and waited on, by every page load. Kept in the local cache
	// (memcache.local): whether the editor is reachable is per web server, and one node's
	// failure must not hide discovery from the others. Without a local cache NC hands out a
	// null cache and failures aren't remembered; a Redis local cache shares the marker.
	private const string FAILURE_CACHE_KEY = 'discovery_failed';
	private const int FAILURE_CACHE_TTL = 60;
	// The editor's proof keys, remembered in app config rather than a memcache: WOPI requests
	// check them on every call, which must not mean a discovery fetch per request on instances
	// without memcache.local, and once the editor has been seen signing, a failed discovery
	// fetch must not switch the checks off.
	private const string PROOF_KEYS_CONFIG = 'proof_keys';
	private const string PROOF_KEYS_CHECKED_AT_CONFIG = 'proof_keys_checked_at';
	private const string PROOF_KEYS_REFRESHED_AT_CONFIG = 'proof_keys_refreshed_at';
	private const int PROOF_KEYS_TTL = 3600;
	// A proof that doesn't verify against the known keys may mean the editor's keys changed;
	// rereading discovery for it is allowed once per period, so forged proofs can't turn every
	// WOPI request into a discovery fetch.
	private const int PROOF_REFRESH_PERIOD = 300;

	public function __construct(
		private IClientService $clientService,
		private ICacheFactory $cacheFactory,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Return cached discovery XML string, fetching from the editor server if needed.
	 */
	public function get(): ?string {
		$cache = $this->cacheFactory->createDistributed('office');
		/** @psalm-suppress MixedAssignment ICache::get() is untyped; only a string is a cached document */
		$cached = $cache->get(self::CACHE_KEY);
		if (is_string($cached)) {
			return $cached;
		}

		$localCache = $this->cacheFactory->createLocal('office');
		if ($this->getEditorUrl() === '' || $localCache->get(self::FAILURE_CACHE_KEY) !== null) {
			return null;
		}

		try {
			return $this->fetch();
		} catch (\Throwable $e) {
			$localCache->set(self::FAILURE_CACHE_KEY, true, self::FAILURE_CACHE_TTL);
			$this->logger->error('Failed to fetch WOPI discovery: ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Fetch discovery XML from the editor server and cache it.
	 *
	 * @throws \Exception if the request fails
	 */
	public function fetch(): string {
		$client = $this->clientService->newClient();
		$url = $this->getEditorUrl() . '/hosting/discovery';

		$response = $client->get($url, $this->getRequestOptions());
		$body = (string)$response->getBody();

		$cache = $this->cacheFactory->createDistributed('office');
		$cache->set(self::CACHE_KEY, $body, self::CACHE_TTL);

		return $body;
	}

	public function resetCache(): void {
		$cache = $this->cacheFactory->createDistributed('office');
		$cache->remove(self::CACHE_KEY);
		// Only clears this node; other nodes retry once their marker expires.
		$this->cacheFactory->createLocal('office')->remove(self::FAILURE_CACHE_KEY);
		// A different editor server has different keys.
		$this->appConfig->deleteKey(Application::APP_ID, self::PROOF_KEYS_CONFIG);
		$this->appConfig->deleteKey(Application::APP_ID, self::PROOF_KEYS_CHECKED_AT_CONFIG);
	}

	/**
	 * Whether discovery can be read right now, so callers can tell an unreachable or
	 * unconfigured editor server apart from a file type it doesn't handle.
	 */
	public function isAvailable(): bool {
		return $this->get() !== null;
	}

	/**
	 * Return the urlsrc template for the given file extension and action name.
	 *
	 * @param string $extension e.g. 'docx'
	 * @param string $action e.g. 'edit' or 'view'
	 * @return string|null urlsrc template string, or null if not found
	 */
	public function getUrlSrc(string $extension, string $action = 'edit'): ?string {
		// Allowlist: file extensions are plain ASCII alnum — reject anything else to prevent XPath injection.
		if (!preg_match('/^[a-zA-Z0-9]{1,20}$/', $extension)) {
			return null;
		}

		$xml = $this->get();
		if ($xml === null) {
			return null;
		}

		try {
			// LIBXML_NONET prevents libxml from making network requests while parsing (e.g. external DTD subsets).
			$parsed = new SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOCDATA);
		} catch (\Exception $e) {
			$this->logger->error('Failed to parse WOPI discovery XML: ' . $e->getMessage());
			return null;
		}

		$actions = $parsed->xpath(
			sprintf('//app/action[@ext="%s" and @name="%s"]', $extension, $action)
		);

		if (!is_array($actions) || $actions === []) {
			return null;
		}

		return (string)$actions[0]['urlsrc'];
	}

	/**
	 * Return all MIME types advertised by the editor's discovery XML.
	 *
	 * Returns an empty array if discovery XML is unavailable or unparseable.
	 *
	 * @return string[]
	 */
	public function getSupportedMimeTypes(): array {
		$xml = $this->get();
		if ($xml === null) {
			return [];
		}

		try {
			$parsed = new SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOCDATA);
		} catch (\Exception $e) {
			$this->logger->error('Failed to parse WOPI discovery XML: ' . $e->getMessage());
			return [];
		}

		$names = $parsed->xpath('//app/@name');
		$mimes = [];
		foreach (is_array($names) ? $names : [] as $name) {
			$mime = (string)$name;
			if ($mime !== '' && str_contains($mime, '/')) {
				$mimes[] = $mime;
			}
		}

		return array_values(array_unique($mimes));
	}

	/**
	 * The editor's proof keys, or null when it doesn't sign its requests.
	 *
	 * Rechecked against discovery once per PROOF_KEYS_TTL. While discovery can't be read,
	 * the last known keys stay in force.
	 */
	public function getProofKeys(): ?WopiProofKeys {
		$now = $this->timeFactory->getTime();
		if ($this->appConfig->getValueInt(Application::APP_ID, self::PROOF_KEYS_CHECKED_AT_CONFIG) > $now - self::PROOF_KEYS_TTL) {
			return $this->storedProofKeys();
		}

		$xml = $this->get();
		if ($xml === null) {
			return $this->storedProofKeys();
		}

		try {
			$keys = $this->parseProofKeys($xml);
		} catch (\Exception $e) {
			$this->logger->error('Failed to parse WOPI discovery XML: ' . $e->getMessage());
			return $this->storedProofKeys();
		}

		// Four base64 values, which never contain '|'.
		$this->appConfig->setValueString(Application::APP_ID, self::PROOF_KEYS_CONFIG, $keys === null
			? ''
			: implode('|', [$keys->modulus, $keys->exponent, $keys->oldModulus, $keys->oldExponent]));
		$this->appConfig->setValueInt(Application::APP_ID, self::PROOF_KEYS_CHECKED_AT_CONFIG, $now);

		return $keys;
	}

	/**
	 * Reread the proof keys from the editor now, at most once per PROOF_REFRESH_PERIOD;
	 * otherwise return the known keys.
	 */
	public function refreshProofKeys(): ?WopiProofKeys {
		$now = $this->timeFactory->getTime();
		if ($this->appConfig->getValueInt(Application::APP_ID, self::PROOF_KEYS_REFRESHED_AT_CONFIG) <= $now - self::PROOF_REFRESH_PERIOD) {
			$this->appConfig->setValueInt(Application::APP_ID, self::PROOF_KEYS_REFRESHED_AT_CONFIG, $now);
			$this->appConfig->setValueInt(Application::APP_ID, self::PROOF_KEYS_CHECKED_AT_CONFIG, 0);
			$this->cacheFactory->createDistributed('office')->remove(self::CACHE_KEY);
		}

		return $this->getProofKeys();
	}

	/**
	 * @throws \Exception on malformed XML
	 */
	private function parseProofKeys(string $xml): ?WopiProofKeys {
		$parsed = new SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOCDATA);

		$elements = $parsed->xpath('//proof-key');
		if (!is_array($elements) || $elements === []) {
			return null;
		}

		$attributes = $elements[0]->attributes();
		$modulus = (string)($attributes['modulus'] ?? '');
		$exponent = (string)($attributes['exponent'] ?? '');
		if ($modulus === '' || $exponent === '') {
			return null;
		}

		$oldModulus = (string)($attributes['oldmodulus'] ?? '');
		$oldExponent = (string)($attributes['oldexponent'] ?? '');

		return new WopiProofKeys(
			$modulus,
			$exponent,
			$oldModulus !== '' ? $oldModulus : $modulus,
			$oldExponent !== '' ? $oldExponent : $exponent,
		);
	}

	private function storedProofKeys(): ?WopiProofKeys {
		$parts = explode('|', $this->appConfig->getValueString(Application::APP_ID, self::PROOF_KEYS_CONFIG));
		if (count($parts) !== 4 || in_array('', $parts, true)) {
			return null;
		}

		return new WopiProofKeys($parts[0], $parts[1], $parts[2], $parts[3]);
	}

	/**
	 * Build the final editor URL by substituting the wopisrc template parameter.
	 *
	 * The WOPI urlsrc is a template like:
	 *   http://editor/hosting/wopi/word/edit?<wopisrc=WOPI_SOURCE&>&
	 * This method replaces <wopisrc=WOPI_SOURCE&> with the actual WOPISrc value.
	 *
	 * @param string $urlsrc Raw urlsrc from discovery XML
	 * @param string $wopiSrc The WOPI host URL (our CheckFileInfo endpoint)
	 * @param string $token WOPI access token
	 */
	public function buildEditorUrl(string $urlsrc, string $wopiSrc, string $token): string {
		// Rewrite the internal wopi_url origin to the public_wopi_url origin so browsers can reach the editor.
		// NC server fetches discovery via the internal Docker hostname (wopi_url); the browser must use the
		// public hostname (public_wopi_url).  Mirrors the eurooffice DocumentServerInternalUrl/DocumentServerUrl split.
		$internalUrl = $this->getEditorUrl();
		$publicUrl = rtrim($this->appConfig->getValueString('office', 'public_wopi_url', ''), '/');
		if ($publicUrl !== '' && $internalUrl !== '' && str_starts_with($urlsrc, $internalUrl)) {
			$urlsrc = $publicUrl . substr($urlsrc, strlen($internalUrl));
		}

		$wopiSrc = $this->editorReachableUrl($wopiSrc);

		// Strip WOPI template placeholders like <wopisrc=WOPI_SOURCE&> leaving bare ? and & separators.
		$url = rtrim((string)preg_replace('/<[^>]+>/', '', $urlsrc), '?&');
		// Preserve the ? already in the base URL if present; otherwise start the query string.
		$separator = str_contains($url, '?') ? '&' : '?';
		$url .= $separator . 'wopisrc=' . urlencode($wopiSrc) . '&access_token=' . urlencode($token);
		return $url;
	}

	/**
	 * A Nextcloud URL as the editor server reaches it: the origin swapped for callback_url
	 * when one is configured. Nextcloud generates absolute URLs from the browser-facing
	 * host, which may not resolve inside the editor's network. Mirrors the eurooffice
	 * "server address for internal requests" setting.
	 */
	public function editorReachableUrl(string $url): string {
		$callbackUrl = rtrim($this->appConfig->getValueString('office', 'callback_url', ''), '/');
		if ($callbackUrl === '') {
			return $url;
		}

		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['host'])) {
			return $url;
		}

		$origin = ($parts['scheme'] ?? 'http') . '://' . $parts['host']
			. (isset($parts['port']) ? ':' . $parts['port'] : '');
		return $callbackUrl . substr($url, strlen($origin));
	}

	private function getEditorUrl(): string {
		return rtrim($this->appConfig->getValueString('office', 'wopi_url', ''), '/');
	}

	private function getRequestOptions(): array {
		$options = [
			'connect_timeout' => 5,
			'timeout' => 15,
			'nextcloud' => ['allow_local_address' => true],
		];

		if ($this->appConfig->getValueString('office', 'disable_certificate_verification') === 'yes') {
			$options['verify'] = false;
		}

		return $options;
	}
}
