<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Controller;

use OCA\Office\Controller\ShareController;
use OCA\Office\Db\Wopi;
use OCA\Office\Service\DiscoveryService;
use OCA\Office\TokenManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Constants;
use OCP\Files\File;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ShareControllerTest extends TestCase {
	private DiscoveryService&MockObject $discoveryService;
	private ShareController $controller;

	protected function setUp(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(82);
		$file->method('getName')->willReturn('report.docx');

		$share = $this->createMock(IShare::class);
		$share->method('getPassword')->willReturn(null);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$share->method('getShareOwner')->willReturn('alice');
		$share->method('getNode')->willReturn($file);

		$shareManager = $this->createMock(IShareManager::class);
		$shareManager->method('getShareByToken')->with('shareTok')->willReturn($share);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('isLoggedIn')->willReturn(false);

		$wopi = new Wopi();
		$wopi->setToken('tok123');
		$wopi->setServerHost('http://nc.local/');

		$tokenManager = $this->createMock(TokenManager::class);
		$tokenManager->method('generateGuestToken')->willReturn($wopi);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('http://nc.local/index.php/apps/office/wopi/files/82');

		$this->discoveryService = $this->createMock(DiscoveryService::class);
		$this->discoveryService->method('getUrlSrc')->willReturn('http://eo-internal/hosting/wopi/word/edit?<rs=DC_LLCC&>');

		$this->controller = new ShareController(
			'office',
			$this->createMock(IRequest::class),
			$shareManager,
			$this->createMock(ISession::class),
			$userSession,
			$tokenManager,
			$this->discoveryService,
			$urlGenerator,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testSharedEditorPageAllowsTheEditorUrlOrigin(): void {
		$this->discoveryService->method('buildEditorUrl')
			->willReturn('https://office.example.com:8443/hosting/wopi/word/edit?wopisrc=x&access_token=tok123');

		$response = $this->controller->openShare('shareTok');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		$this->assertMatchesRegularExpression('#frame-src [^;]*https://office\.example\.com:8443(;| |$)#', $policy);
		$this->assertMatchesRegularExpression('#form-action [^;]*https://office\.example\.com:8443(;| |$)#', $policy);
	}

	public function testUnusableEditorUrlFailsInsteadOfRenderingABlockedPage(): void {
		$this->discoveryService->method('buildEditorUrl')->willReturn('/hosting/wopi/word/edit?access_token=tok123');

		$response = $this->controller->openShare('shareTok');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}
}
