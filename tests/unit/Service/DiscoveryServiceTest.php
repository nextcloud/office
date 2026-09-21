<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Service;

use OCA\Office\Service\DiscoveryService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DiscoveryServiceTest extends TestCase {
	private IClientService&MockObject $clientService;
	private ICacheFactory&MockObject $cacheFactory;
	private IAppConfig&MockObject $appConfig;
	private LoggerInterface&MockObject $logger;
	private ICache&MockObject $cache;
	private DiscoveryService $service;

	protected function setUp(): void {
		$this->clientService = $this->createMock(IClientService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->cache = $this->createMock(ICache::class);

		$this->cacheFactory->method('createDistributed')
			->with('office')
			->willReturn($this->cache);

		$this->service = new DiscoveryService(
			$this->clientService,
			$this->cacheFactory,
			$this->appConfig,
			$this->logger,
		);
	}

	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../fixtures/' . $name);
	}

	/** @param array<string, string> $values */
	private function mockAppConfig(array $values): void {
		$this->appConfig->method('getValueString')
			->willReturnCallback(static fn (string $app, string $key, string $default = '') => $values[$key] ?? $default);
	}

	private function withDiscoveryXml(string $xml): void {
		$this->cache->method('get')->with('discovery')->willReturn($xml);
	}

	private function mockFetchReturns(string $body): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);

		$this->clientService->method('newClient')->willReturn($client);
	}

	// --- get() / fetch() / resetCache() ---

	public function testGetReturnsCachedValueWithoutFetching(): void {
		$this->cache->method('get')->with('discovery')->willReturn('<cached/>');
		$this->clientService->expects($this->never())->method('newClient');

		$this->assertSame('<cached/>', $this->service->get());
	}

	public function testGetFetchesAndCachesOnCacheMiss(): void {
		$this->cache->method('get')->willReturn(null);
		$this->mockAppConfig(['wopi_url' => 'http://eo']);
		$this->mockFetchReturns('<fresh/>');

		$this->cache->expects($this->once())
			->method('set')
			->with('discovery', '<fresh/>', 3600);

		$this->assertSame('<fresh/>', $this->service->get());
	}

	public function testGetReturnsNullAndLogsWhenFetchThrows(): void {
		$this->cache->method('get')->willReturn(null);
		$this->mockAppConfig(['wopi_url' => 'http://eo']);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \Exception('connection refused'));
		$this->clientService->method('newClient')->willReturn($client);

		$this->logger->expects($this->once())->method('error');

		$this->assertNull($this->service->get());
	}

	public function testFetchRequestsDiscoveryUrlWithOptions(): void {
		$this->mockAppConfig(['wopi_url' => 'http://eo']);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('<discovery/>');

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with(
				'http://eo/hosting/discovery',
				$this->callback(static function (array $options): bool {
					return $options['timeout'] === 45
						&& $options['nextcloud']['allow_local_address'] === true
						&& !isset($options['verify']);
				})
			)
			->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

		$this->assertSame('<discovery/>', $this->service->fetch());
	}

	public function testFetchDisablesCertificateVerificationWhenConfigured(): void {
		$this->mockAppConfig([
			'wopi_url' => 'http://eo',
			'disable_certificate_verification' => 'yes',
		]);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('<discovery/>');

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with(
				$this->anything(),
				$this->callback(static fn (array $options): bool => ($options['verify'] ?? null) === false)
			)
			->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

		$this->service->fetch();
	}

	public function testResetCacheRemovesCacheKey(): void {
		$this->cache->expects($this->once())->method('remove')->with('discovery');
		$this->service->resetCache();
	}

	// --- getUrlSrc() ---

	public function testGetUrlSrcRejectsNonAlnumExtension(): void {
		$this->clientService->expects($this->never())->method('newClient');
		$this->assertNull($this->service->getUrlSrc('docx" or "1"="1'));
	}

	public function testGetUrlSrcRejectsExtensionLongerThan20Chars(): void {
		$this->assertNull($this->service->getUrlSrc(str_repeat('a', 21)));
	}

	public function testGetUrlSrcRejectsEmptyExtension(): void {
		$this->assertNull($this->service->getUrlSrc(''));
	}

	public function testGetUrlSrcReturnsUrlsrcForMatchingExtensionAndAction(): void {
		$this->withDiscoveryXml($this->fixture('discovery-onlyoffice.xml'));
		$urlsrc = $this->service->getUrlSrc('docx', 'edit');
		$this->assertStringContainsString('hosting/wopi/word/edit', (string)$urlsrc);
	}

	public function testGetUrlSrcDefaultsToEditAction(): void {
		$this->withDiscoveryXml($this->fixture('discovery-onlyoffice.xml'));
		$urlsrc = $this->service->getUrlSrc('docx');
		$this->assertStringContainsString('hosting/wopi/word/edit', (string)$urlsrc);
	}

	public function testGetUrlSrcFindsViewAction(): void {
		$this->withDiscoveryXml($this->fixture('discovery-onlyoffice.xml'));
		$urlsrc = $this->service->getUrlSrc('docx', 'view');
		$this->assertStringContainsString('hosting/wopi/word/view', (string)$urlsrc);
	}

	public function testGetUrlSrcReturnsNullWhenActionNotFoundForExtension(): void {
		$this->withDiscoveryXml($this->fixture('discovery-onlyoffice.xml'));
		// pdf only has a "view" action in the fixture, not "edit".
		$this->assertNull($this->service->getUrlSrc('pdf', 'edit'));
	}

	public function testGetUrlSrcReturnsNullWhenExtensionNotInDiscovery(): void {
		$this->withDiscoveryXml($this->fixture('discovery-onlyoffice.xml'));
		$this->assertNull($this->service->getUrlSrc('odt', 'edit'));
	}

	public function testGetUrlSrcReturnsNullWhenDiscoveryFetchFails(): void {
		$this->cache->method('get')->willReturn(null);
		$this->mockAppConfig(['wopi_url' => 'http://eo']);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \Exception('timeout'));
		$this->clientService->method('newClient')->willReturn($client);

		$this->assertNull($this->service->getUrlSrc('docx'));
	}

	public function testGetUrlSrcReturnsNullAndLogsOnMalformedXml(): void {
		$this->withDiscoveryXml('<not valid xml');
		$this->logger->expects($this->once())->method('error');
		$this->assertNull(@$this->service->getUrlSrc('docx'));
	}

	// --- getSupportedMimeTypes() ---

	public function testGetSupportedMimeTypesReturnsEmptyArrayForOnlyOfficeStyleDiscovery(): void {
		// KNOWN BUG (documented, not fixed): getSupportedMimeTypes() assumes app
		// name === MIME type (Collabora-style). OnlyOffice/Euro-Office discovery
		// names apps "Word"/"Excel"/etc, so this always returns [] against it.
		$this->withDiscoveryXml($this->fixture('discovery-onlyoffice.xml'));
		$this->assertSame([], $this->service->getSupportedMimeTypes());
	}

	public function testGetSupportedMimeTypesReturnsMimeTypesForCollaboraStyleDiscovery(): void {
		$this->withDiscoveryXml($this->fixture('discovery-collabora.xml'));
		$mimes = $this->service->getSupportedMimeTypes();

		$this->assertContains('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $mimes);
		$this->assertContains('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $mimes);
	}

	public function testGetSupportedMimeTypesDeduplicatesRepeatedMimeTypes(): void {
		// discovery-collabora.xml has two <app name="...wordprocessingml.document">
		// entries (edit action's app matches itself once, but the XPath selects
		// //app/@name so a MIME type appearing on more than one <app> must not
		// be duplicated in the result).
		$this->withDiscoveryXml($this->fixture('discovery-collabora.xml'));
		$mimes = $this->service->getSupportedMimeTypes();

		$this->assertSame(array_values(array_unique($mimes)), $mimes);
	}

	public function testGetSupportedMimeTypesReturnsEmptyArrayWhenDiscoveryUnavailable(): void {
		$this->cache->method('get')->willReturn(null);
		$this->mockAppConfig(['wopi_url' => 'http://eo']);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \Exception('timeout'));
		$this->clientService->method('newClient')->willReturn($client);

		$this->assertSame([], $this->service->getSupportedMimeTypes());
	}

	public function testGetSupportedMimeTypesReturnsEmptyArrayAndLogsOnMalformedXml(): void {
		$this->withDiscoveryXml('<not valid xml');
		$this->logger->expects($this->once())->method('error');
		$this->assertSame([], @$this->service->getSupportedMimeTypes());
	}

	// --- buildEditorUrl() ---

	public function testBuildEditorUrlStripsPlaceholdersAndAppendsWopiSrcAndToken(): void {
		$this->mockAppConfig([]);

		$url = $this->service->buildEditorUrl(
			'http://eo/hosting/wopi/word/edit?<rs=DC_LLCC&>',
			'http://nc.local/index.php/apps/office/wopi/files/82',
			'tok123',
		);

		$this->assertSame(
			'http://eo/hosting/wopi/word/edit?wopisrc=' . urlencode('http://nc.local/index.php/apps/office/wopi/files/82') . '&access_token=tok123',
			$url,
		);
	}

	public function testBuildEditorUrlUsesAmpersandSeparatorWhenQueryStringRemains(): void {
		$this->mockAppConfig([]);

		$url = $this->service->buildEditorUrl(
			'http://eo/x?foo=bar&<rs=DC_LLCC&>',
			'http://nc.local/wopi',
			'tok',
		);

		$this->assertStringStartsWith('http://eo/x?foo=bar&wopisrc=', $url);
	}

	public function testBuildEditorUrlStripsMultiplePlaceholders(): void {
		$this->mockAppConfig([]);

		$url = $this->service->buildEditorUrl(
			'http://eo/hosting/wopi/word/edit?<rs=DC_LLCC&><dchat=DISABLE_CHAT&>',
			'http://nc.local/wopi',
			'tok',
		);

		$this->assertStringNotContainsString('<', $url);
		$this->assertStringNotContainsString('>', $url);
		$this->assertStringStartsWith('http://eo/hosting/wopi/word/edit?wopisrc=', $url);
	}

	public function testBuildEditorUrlSwapsToPublicWopiUrlWhenUrlsrcMatchesInternal(): void {
		$this->mockAppConfig([
			'wopi_url' => 'http://eo',
			'public_wopi_url' => 'https://eo.public.example.com',
		]);

		$url = $this->service->buildEditorUrl(
			'http://eo/hosting/wopi/word/edit?<rs=DC_LLCC&>',
			'http://nc.local/wopi',
			'tok',
		);

		$this->assertStringStartsWith('https://eo.public.example.com/hosting/wopi/word/edit?wopisrc=', $url);
	}

	public function testBuildEditorUrlLeavesUrlsrcUnchangedWhenNotMatchingInternalPrefix(): void {
		$this->mockAppConfig([
			'wopi_url' => 'http://eo',
			'public_wopi_url' => 'https://eo.public.example.com',
		]);

		$url = $this->service->buildEditorUrl(
			'http://other-host/hosting/wopi/word/edit?<rs=DC_LLCC&>',
			'http://nc.local/wopi',
			'tok',
		);

		$this->assertStringStartsWith('http://other-host/hosting/wopi/word/edit?wopisrc=', $url);
	}

	public function testBuildEditorUrlIsNoOpWhenPublicWopiUrlNotConfigured(): void {
		$this->mockAppConfig(['wopi_url' => 'http://eo']);

		$url = $this->service->buildEditorUrl(
			'http://eo/hosting/wopi/word/edit?<rs=DC_LLCC&>',
			'http://nc.local/wopi',
			'tok',
		);

		$this->assertStringStartsWith('http://eo/hosting/wopi/word/edit?wopisrc=', $url);
	}

	public function testBuildEditorUrlSwapsWopiSrcOriginToCallbackUrl(): void {
		$this->mockAppConfig(['callback_url' => 'http://nc-internal:8080']);

		$url = $this->service->buildEditorUrl(
			'http://eo/hosting/wopi/word/edit?<rs=DC_LLCC&>',
			'https://nc.public.example.com:443/index.php/apps/office/wopi/files/82',
			'tok',
		);

		$expectedWopiSrc = 'http://nc-internal:8080/index.php/apps/office/wopi/files/82';
		$this->assertStringContainsString('wopisrc=' . urlencode($expectedWopiSrc), $url);
	}

	public function testBuildEditorUrlSwapsWopiSrcWithHttpsPortAndNoPath(): void {
		$this->mockAppConfig(['callback_url' => 'http://nc-internal:8080']);

		$url = $this->service->buildEditorUrl(
			'http://eo/hosting/wopi/word/edit?<rs=DC_LLCC&>',
			'https://nc.public.example.com:8443',
			'tok',
		);

		$this->assertStringContainsString('wopisrc=' . urlencode('http://nc-internal:8080'), $url);
	}

	public function testBuildEditorUrlLeavesWopiSrcUnchangedWhenUnparsable(): void {
		$this->mockAppConfig(['callback_url' => 'http://nc-internal:8080']);

		$url = $this->service->buildEditorUrl(
			'http://eo/hosting/wopi/word/edit?<rs=DC_LLCC&>',
			'not-a-url',
			'tok',
		);

		$this->assertStringContainsString('wopisrc=' . urlencode('not-a-url'), $url);
	}

	public function testBuildEditorUrlAppliesBothPublicWopiUrlAndCallbackUrlTransformations(): void {
		$this->mockAppConfig([
			'wopi_url' => 'http://eo',
			'public_wopi_url' => 'https://eo.public.example.com',
			'callback_url' => 'http://nc-internal:8080',
		]);

		$url = $this->service->buildEditorUrl(
			'http://eo/hosting/wopi/word/edit?<rs=DC_LLCC&>',
			'https://nc.public.example.com/index.php/apps/office/wopi/files/82',
			'tok',
		);

		$this->assertStringStartsWith('https://eo.public.example.com/hosting/wopi/word/edit?wopisrc=', $url);
		$this->assertStringContainsString(
			urlencode('http://nc-internal:8080/index.php/apps/office/wopi/files/82'),
			$url,
		);
	}

	public function testBuildEditorUrlUrlEncodesTokenAndWopiSrc(): void {
		$this->mockAppConfig([]);

		$url = $this->service->buildEditorUrl(
			'http://eo/hosting/wopi/word/edit',
			'http://nc.local/wopi?a=b',
			'tok en/2',
		);

		$this->assertStringContainsString('access_token=' . urlencode('tok en/2'), $url);
		$this->assertStringContainsString('wopisrc=' . urlencode('http://nc.local/wopi?a=b'), $url);
	}
}
