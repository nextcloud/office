<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Service;

use OCA\Office\Service\DiscoveryService;
use OCP\AppFramework\Utility\ITimeFactory;
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
	private ICache&MockObject $localCache;
	private DiscoveryService $service;
	/** @var array<string, string|int> In-memory app config. */
	private array $config = [];
	private int $now = 1793016000;

	protected function setUp(): void {
		$this->clientService = $this->createMock(IClientService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default),
		);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->config[$key] ?? $default),
		);
		$this->appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$this->appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$this->appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->cache = $this->createMock(ICache::class);
		$this->localCache = $this->createMock(ICache::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->cacheFactory->method('createDistributed')
			->with('office')
			->willReturn($this->cache);
		$this->cacheFactory->method('createLocal')
			->with('office')
			->willReturn($this->localCache);

		$this->service = new DiscoveryService(
			$this->clientService,
			$this->cacheFactory,
			$this->appConfig,
			$this->logger,
			$time,
		);
	}

	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../../fixtures/' . $name);
	}

	/** @param array<string, string> $values */
	private function mockAppConfig(array $values): void {
		$this->config = $values;
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
					return $options['timeout'] === 15
						&& $options['connect_timeout'] === 5
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

	public function testResetCacheRemovesDiscoveryAndThisNodesFailureMarker(): void {
		$this->cache->expects($this->once())->method('remove')->with('discovery');
		$this->localCache->expects($this->once())->method('remove')->with('discovery_failed');

		$this->service->resetCache();
	}

	public function testGetDoesNotFetchWhenEditorUrlIsNotConfigured(): void {
		$this->cache->method('get')->willReturn(null);
		$this->mockAppConfig([]);
		$this->clientService->expects($this->never())->method('newClient');
		$this->logger->expects($this->never())->method('error');

		$this->assertNull($this->service->get());
	}

	public function testGetRemembersAFailedFetchBriefly(): void {
		$this->cache->method('get')->willReturn(null);
		$this->mockAppConfig(['wopi_url' => 'http://eo']);

		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \Exception('connection timed out'));
		$this->clientService->method('newClient')->willReturn($client);

		$this->cache->expects($this->never())->method('set');
		$this->localCache->expects($this->once())->method('set')->with('discovery_failed', true, 60);

		$this->assertNull($this->service->get());
	}

	public function testGetDoesNotRetryWhileAFailureIsRemembered(): void {
		$this->cache->method('get')->willReturn(null);
		$this->localCache->method('get')->with('discovery_failed')->willReturn(true);
		$this->mockAppConfig(['wopi_url' => 'http://eo']);
		$this->clientService->expects($this->never())->method('newClient');

		$this->assertNull($this->service->get());
	}

	public function testIsAvailableReflectsWhetherDiscoveryCanBeRead(): void {
		$this->cache->method('get')->willReturn(null);
		$this->mockAppConfig([]);

		$this->assertFalse($this->service->isAvailable());
	}

	public function testIsAvailableWithCachedDiscovery(): void {
		$this->withDiscoveryXml('<wopi-discovery/>');

		$this->assertTrue($this->service->isAvailable());
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

	// --- editorReachableUrl() ---

	public function testEditorReachableUrlSwapsTheOriginForCallbackUrl(): void {
		$this->mockAppConfig(['callback_url' => 'http://nextcloud-internal:8080/']);

		$this->assertSame(
			'http://nextcloud-internal:8080/index.php/apps/office/wopi/files/82?access_token=tok',
			$this->service->editorReachableUrl('https://cloud.example.com/index.php/apps/office/wopi/files/82?access_token=tok'),
		);
	}

	public function testEditorReachableUrlIsUnchangedWithoutCallbackUrl(): void {
		$this->mockAppConfig([]);

		$this->assertSame('https://cloud.example.com/x', $this->service->editorReachableUrl('https://cloud.example.com/x'));
	}

	// --- getProofKeys() / refreshProofKeys() ---

	public function testGetProofKeysReadsCurrentAndOldKey(): void {
		$this->withDiscoveryXml('<wopi-discovery><net-zone/><proof-key oldvalue="x" oldmodulus="T2xk" oldexponent="AQAB" value="y" modulus="Q3Vy" exponent="AQAB"/></wopi-discovery>');

		$keys = $this->service->getProofKeys();

		$this->assertNotNull($keys);
		$this->assertSame('Q3Vy', $keys->modulus);
		$this->assertSame('AQAB', $keys->exponent);
		$this->assertSame('T2xk', $keys->oldModulus);
	}

	public function testGetProofKeysFallsBackToTheCurrentKeyWithoutAnOldOne(): void {
		$this->withDiscoveryXml('<wopi-discovery><proof-key modulus="Q3Vy" exponent="AQAB"/></wopi-discovery>');

		$this->assertSame('Q3Vy', $this->service->getProofKeys()?->oldModulus);
	}

	public function testGetProofKeysIsNullWhenTheEditorDoesNotSign(): void {
		$this->withDiscoveryXml($this->fixture('discovery-onlyoffice.xml'));

		$this->assertNull($this->service->getProofKeys());
	}

	private const string SIGNING = '<wopi-discovery><proof-key modulus="Q3Vy" exponent="AQAB" oldmodulus="T2xk" oldexponent="AQAB"/></wopi-discovery>';
	private const string ROTATED = '<wopi-discovery><proof-key modulus="TmV3" exponent="AQAB" oldmodulus="Q3Vy" oldexponent="AQAB"/></wopi-discovery>';

	/** @param list<?string> $documents what successive discovery reads return (null: unavailable) */
	private function discoveryReturns(array $documents): void {
		$this->cache->method('get')->willReturnCallback(static function () use (&$documents): ?string {
			return count($documents) > 1 ? array_shift($documents) : $documents[0];
		});
	}

	public function testKnownKeysAreReusedWithoutRereadingDiscoveryForAnHour(): void {
		$this->discoveryReturns([self::SIGNING, self::ROTATED]);
		$this->service->getProofKeys();

		$this->now += 3599;
		$this->assertSame('Q3Vy', $this->service->getProofKeys()?->modulus);

		$this->now += 1;
		$this->assertSame('TmV3', $this->service->getProofKeys()?->modulus);
	}

	public function testLastKnownKeysStayInForceWhileDiscoveryIsUnreadable(): void {
		// Not cached any more, and the editor can't be reached for a fresh copy.
		$this->discoveryReturns([self::SIGNING, null]);
		$this->config['wopi_url'] = 'http://eo';
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \Exception('connection refused'));
		$this->clientService->method('newClient')->willReturn($client);
		$this->service->getProofKeys();

		$this->now += 7200;

		$this->assertSame('Q3Vy', $this->service->getProofKeys()?->modulus);
	}

	public function testAnEditorThatStopsSigningIsSeenAfterTheNextCheck(): void {
		$this->discoveryReturns([self::SIGNING, '<wopi-discovery/>']);
		$this->service->getProofKeys();

		$this->now += 3600;

		$this->assertNull($this->service->getProofKeys());
	}

	public function testRefreshPicksUpRotatedKeysAtOncePerPeriod(): void {
		$this->discoveryReturns([self::SIGNING, self::ROTATED]);
		$this->service->getProofKeys();
		$this->cache->expects($this->once())->method('remove')->with('discovery');

		$this->assertSame('TmV3', $this->service->refreshProofKeys()?->modulus);
		$this->now += 60;
		$this->service->refreshProofKeys();
	}

	public function testRefreshIsAllowedAgainAfterThePeriod(): void {
		$this->discoveryReturns([self::SIGNING]);
		$this->cache->expects($this->exactly(2))->method('remove')->with('discovery');

		$this->service->refreshProofKeys();
		$this->now += 300;
		$this->service->refreshProofKeys();
	}

	public function testResetCacheForgetsTheKeysOfThePreviousEditor(): void {
		$this->discoveryReturns([self::SIGNING, '<wopi-discovery/>']);
		$this->service->getProofKeys();

		$this->service->resetCache();

		$this->assertNull($this->service->getProofKeys());
	}
}
