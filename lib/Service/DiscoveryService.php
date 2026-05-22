<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Service;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

class DiscoveryService {
	private const CACHE_KEY = 'discovery';
	private const CACHE_TTL = 3600;

	public function __construct(
		private IClientService $clientService,
		private ICacheFactory $cacheFactory,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Return cached discovery XML string, fetching from the editor server if needed.
	 */
	public function get(): ?string {
		$cache = $this->cacheFactory->createDistributed('office');
		$cached = $cache->get(self::CACHE_KEY);
		if ($cached !== null) {
			return $cached;
		}

		try {
			return $this->fetch();
		} catch (\Throwable $e) {
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
	}

	/**
	 * Return the urlsrc template for the given file extension and action name.
	 *
	 * @param string $extension e.g. 'docx'
	 * @param string $action    e.g. 'edit' or 'view'
	 * @return string|null urlsrc template string, or null if not found
	 */
	public function getUrlSrc(string $extension, string $action = 'edit'): ?string {
		$xml = $this->get();
		if ($xml === null) {
			return null;
		}

		try {
			$parsed = new SimpleXMLElement($xml);
		} catch (\Exception $e) {
			$this->logger->error('Failed to parse WOPI discovery XML: ' . $e->getMessage());
			return null;
		}

		$actions = $parsed->xpath(
			sprintf('//app/action[@ext="%s" and @name="%s"]', $extension, $action)
		);

		if (empty($actions)) {
			return null;
		}

		return (string)$actions[0]['urlsrc'];
	}

	/**
	 * Build the final editor URL by substituting the wopisrc template parameter.
	 *
	 * The WOPI urlsrc is a template like:
	 *   http://editor/hosting/wopi/word/edit?<wopisrc=WOPI_SOURCE&>&
	 * This method replaces <wopisrc=WOPI_SOURCE&> with the actual WOPISrc value.
	 *
	 * @param string $urlsrc   Raw urlsrc from discovery XML
	 * @param string $wopiSrc  The WOPI host URL (our CheckFileInfo endpoint)
	 * @param string $token    WOPI access token
	 */
	public function buildEditorUrl(string $urlsrc, string $wopiSrc, string $token): string {
		$url = preg_replace('/<[^>]+>/', '', $urlsrc);
		$url = rtrim($url, '?&');
		$url .= '&wopisrc=' . urlencode($wopiSrc);
		$url .= '&access_token=' . urlencode($token);
		return $url;
	}

	private function getEditorUrl(): string {
		return rtrim($this->appConfig->getValueString('office', 'wopi_url', ''), '/');
	}

	private function getRequestOptions(): array {
		$options = [
			'timeout' => 45,
			'nextcloud' => ['allow_local_address' => true],
		];

		if ($this->appConfig->getValueString('office', 'disable_certificate_verification') === 'yes') {
			$options['verify'] = false;
		}

		return $options;
	}
}
