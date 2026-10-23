<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Controller;

use OCA\Office\Controller\EditorController;
use OCA\Office\Db\Wopi;
use OCA\Office\Service\DiscoveryService;
use OCA\Office\TokenManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class EditorControllerTest extends TestCase {
	private DiscoveryService&MockObject $discoveryService;
	private EditorController $controller;

	protected function setUp(): void {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('report.docx');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->with(82)->willReturn($file);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$wopi = new Wopi();
		$wopi->setToken('tok123');
		$wopi->setServerHost('http://nc.local/');

		$tokenManager = $this->createMock(TokenManager::class);
		$tokenManager->method('generateToken')->with(82)->willReturn($wopi);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('http://nc.local/index.php/apps/office/wopi/files/82');

		$this->discoveryService = $this->createMock(DiscoveryService::class);
		$this->discoveryService->method('getUrlSrc')->willReturn('http://eo-internal/hosting/wopi/word/edit?<rs=DC_LLCC&>');

		$this->controller = new EditorController(
			'office',
			$this->createMock(IRequest::class),
			$rootFolder,
			$tokenManager,
			$this->discoveryService,
			$urlGenerator,
			$this->createMock(LoggerInterface::class),
			'alice',
		);
	}

	public function testEditorPageAllowsTheEditorUrlOrigin(): void {
		// Deliberately not the urlsrc host: the browser is sent to the rewritten public URL.
		$this->discoveryService->method('buildEditorUrl')
			->willReturn('https://office.example.com:8443/hosting/wopi/word/edit?wopisrc=x&access_token=tok123');

		$response = $this->controller->open(82);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		$this->assertMatchesRegularExpression('#frame-src [^;]*https://office\.example\.com:8443(;| |$)#', $policy);
		$this->assertMatchesRegularExpression('#form-action [^;]*https://office\.example\.com:8443(;| |$)#', $policy);
		$this->assertStringNotContainsString('eo-internal', $policy);
	}

	public function testUnusableEditorUrlFailsInsteadOfRenderingABlockedPage(): void {
		$this->discoveryService->method('buildEditorUrl')->willReturn('/hosting/wopi/word/edit?access_token=tok123');

		$response = $this->controller->open(82);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}
}
