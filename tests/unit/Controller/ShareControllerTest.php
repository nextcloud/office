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
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ShareControllerTest extends TestCase {
	private IShare&MockObject $share;
	private Node $node;
	private ?string $urlsrc = 'http://eo-internal/hosting/wopi/word/edit?<rs=DC_LLCC&>';
	private bool $discoveryAvailable = true;
	private int $permissions = Constants::PERMISSION_READ;
	private ISession&MockObject $session;
	private IUserSession&MockObject $userSession;
	private TokenManager&MockObject $tokenManager;
	private DiscoveryService&MockObject $discoveryService;
	private ShareController $controller;

	protected function setUp(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(82);
		$file->method('getName')->willReturn('report.docx');
		$this->node = $file;

		$this->share = $this->createMock(IShare::class);
		$this->share->method('getId')->willReturn('7');
		$this->share->method('getToken')->willReturn('shareTok');
		$this->share->method('getPermissions')->willReturnCallback(fn (): int => $this->permissions);
		$this->share->method('getShareOwner')->willReturn('alice');
		$this->share->method('getNode')->willReturnCallback(fn (): Node => $this->node);

		$shareManager = $this->createMock(IShareManager::class);
		$shareManager->method('getShareByToken')->willReturnCallback(function (string $token): IShare {
			if ($token !== 'shareTok') {
				throw new \OCP\Share\Exceptions\ShareNotFound();
			}
			return $this->share;
		});

		$this->session = $this->createMock(ISession::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->tokenManager = $this->createMock(TokenManager::class);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('http://nc.local/index.php/apps/office/wopi/files/82');
		$urlGenerator->method('linkToRoute')->willReturn('/index.php/s/shareTok');

		$this->discoveryService = $this->createMock(DiscoveryService::class);
		$this->discoveryService->method('isAvailable')->willReturnCallback(fn (): bool => $this->discoveryAvailable);
		$this->discoveryService->method('getUrlSrc')->willReturnCallback(fn (): ?string => $this->urlsrc);

		$this->controller = new ShareController(
			'office',
			$this->createMock(IRequest::class),
			$shareManager,
			$this->session,
			$this->userSession,
			$this->tokenManager,
			$this->discoveryService,
			$urlGenerator,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function wopi(): Wopi {
		$wopi = new Wopi();
		$wopi->setToken('tok123');
		$wopi->setServerHost('http://nc.local/');
		$wopi->setExpiry(1700036000);

		return $wopi;
	}

	private function editorUrlResolves(): void {
		$this->discoveryService->method('buildEditorUrl')
			->willReturn('https://office.example.com:8443/hosting/wopi/word/edit?wopisrc=x&access_token=tok123');
	}

	private function loggedInAs(string $displayName): void {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('getUID')->willReturn(strtolower($displayName));
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function passwordProtected(): void {
		$this->share->method('getPassword')->willReturn('hashed-password');
		$this->session->method('get')->with('public_link_authenticated')->willReturn(null);
	}

	public function testSharedEditorPageAllowsTheEditorUrlOrigin(): void {
		$this->tokenManager->method('generateGuestToken')->willReturn($this->wopi());
		$this->editorUrlResolves();

		$response = $this->controller->openShare('shareTok');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$policy = $response->getContentSecurityPolicy()->buildPolicy();
		$this->assertMatchesRegularExpression('#frame-src [^;]*https://office\.example\.com:8443(;| |$)#', $policy);
		$this->assertMatchesRegularExpression('#form-action [^;]*https://office\.example\.com:8443(;| |$)#', $policy);
		$this->assertSame(1700036000000, $response->getParams()['accessTokenTtl']);
	}

	public function testUnusableEditorUrlFailsInsteadOfRenderingABlockedPage(): void {
		$this->tokenManager->method('generateGuestToken')->willReturn($this->wopi());
		$this->discoveryService->method('buildEditorUrl')->willReturn('/hosting/wopi/word/edit?access_token=tok123');

		$response = $this->controller->openShare('shareTok');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	public function testLoggedInUserWithOwnAccessGetsTheirOwnToken(): void {
		$this->loggedInAs('Bob');
		$this->tokenManager->expects($this->once())->method('generateToken')->with(82)->willReturn($this->wopi());
		$this->tokenManager->expects($this->never())->method('generateGuestToken');
		$this->editorUrlResolves();

		$this->assertInstanceOf(TemplateResponse::class, $this->controller->openShare('shareTok'));
	}

	public function testLoggedInUserWithoutOwnAccessOpensThroughTheLinkUnderTheirName(): void {
		$this->loggedInAs('Bob');
		$this->tokenManager->method('generateToken')->willThrowException(new NotPermittedException());
		$this->tokenManager->expects($this->once())->method('generateGuestToken')
			->with(82, 'alice', 'shareTok', 'bob', 'Bob', false, false)
			->willReturn($this->wopi());
		$this->editorUrlResolves();

		$this->assertInstanceOf(TemplateResponse::class, $this->controller->openShare('shareTok', guestName: 'Someone Else'));
	}

	public function testLoggedInUserWithoutOwnAccessKeepsTheLinksDownloadRestriction(): void {
		$this->loggedInAs('Bob');
		$this->share->method('getHideDownload')->willReturn(true);
		$this->tokenManager->method('generateToken')->willThrowException(new NotPermittedException());
		$this->tokenManager->expects($this->once())->method('generateGuestToken')
			->with(82, 'alice', 'shareTok', 'bob', 'Bob', false, true)
			->willReturn($this->wopi());
		$this->editorUrlResolves();

		$this->assertInstanceOf(TemplateResponse::class, $this->controller->openShare('shareTok'));
	}

	public function testLoggedInUserWithoutOwnAccessStillNeedsTheLinkPassword(): void {
		$this->loggedInAs('Bob');
		$this->passwordProtected();
		$this->tokenManager->method('generateToken')->willThrowException(new NotPermittedException());
		$this->tokenManager->expects($this->never())->method('generateGuestToken');

		$this->assertInstanceOf(RedirectResponse::class, $this->controller->openShare('shareTok'));
	}

	public function testPendingPasswordHidesWhetherAPathExistsInAFolderShare(): void {
		$this->loggedInAs('Bob');
		$this->passwordProtected();
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$this->node = $folder;

		$this->assertInstanceOf(RedirectResponse::class, $this->controller->openShare('shareTok', path: 'secret-plans.docx'));
	}

	public function testPendingPasswordHidesThatTheShareIsUnreadable(): void {
		$this->loggedInAs('Bob');
		$this->passwordProtected();
		$this->permissions = 0;

		$this->assertInstanceOf(RedirectResponse::class, $this->controller->openShare('shareTok'));
	}

	public function testPendingPasswordHidesThatAPathIsNotPermitted(): void {
		$this->loggedInAs('Bob');
		$this->passwordProtected();
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotPermittedException());
		$this->node = $folder;

		$this->assertInstanceOf(RedirectResponse::class, $this->controller->openShare('shareTok', path: 'locked.docx'));
	}

	public function testPendingPasswordHidesTheFileType(): void {
		$this->loggedInAs('Bob');
		$this->passwordProtected();
		$this->urlsrc = null;

		$this->assertInstanceOf(RedirectResponse::class, $this->controller->openShare('shareTok'));
	}

	public function testUnreachableEditorServerIs503NotUnsupportedType(): void {
		$this->discoveryAvailable = false;

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller->openShare('shareTok')->getStatus());
	}

	public function testPendingPasswordHidesThatTheEditorIsUnreachable(): void {
		$this->loggedInAs('Bob');
		$this->passwordProtected();
		$this->discoveryAvailable = false;

		$this->assertInstanceOf(RedirectResponse::class, $this->controller->openShare('shareTok'));
	}

	public function testMissingPathInAnOpenFolderShareIs404(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException());
		$this->node = $folder;

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->openShare('shareTok', path: 'missing.docx')->getStatus());
	}

	public function testLoggedInUserWithOwnAccessSkipsTheLinkPassword(): void {
		$this->loggedInAs('Bob');
		$this->passwordProtected();
		$this->tokenManager->method('generateToken')->willReturn($this->wopi());
		$this->editorUrlResolves();

		$this->assertInstanceOf(TemplateResponse::class, $this->controller->openShare('shareTok'));
	}

	/** @return array<string, array{mixed}> */
	public static function enteredPasswordSessions(): array {
		return [
			'list of share IDs' => [['3', '7']],
			'legacy single share ID' => ['7'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('enteredPasswordSessions')]
	public function testAnonymousVisitorWhoEnteredTheLinkPasswordGetsIn(mixed $sessionValue): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);
		$this->share->method('getPassword')->willReturn('hashed-password');
		$this->session->method('get')->with('public_link_authenticated')->willReturn($sessionValue);
		$this->tokenManager->method('generateGuestToken')->willReturn($this->wopi());
		$this->editorUrlResolves();

		$this->assertInstanceOf(TemplateResponse::class, $this->controller->openShare('shareTok'));
	}

	public function testAnonymousVisitorNeedsTheLinkPassword(): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);
		$this->passwordProtected();
		$this->tokenManager->expects($this->never())->method('generateGuestToken');

		$this->assertInstanceOf(RedirectResponse::class, $this->controller->openShare('shareTok'));
	}

	public function testUnknownShareTokenCountsAsAGuess(): void {
		$response = $this->controller->openShare('guessed');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertTrue($response->isThrottled());
		$this->assertSame(['action' => 'officeOpenShare'], $response->getThrottleMetadata());
	}

	public function testOpenShareIsRateLimitedAndBruteForceProtected(): void {
		$method = new \ReflectionMethod(ShareController::class, 'openShare');

		$rateLimit = $method->getAttributes(\OCP\AppFramework\Http\Attribute\AnonRateLimit::class)[0]->newInstance();
		$this->assertSame(30, $rateLimit->getLimit());
		$this->assertSame(60, $rateLimit->getPeriod());

		$bruteForce = $method->getAttributes(\OCP\AppFramework\Http\Attribute\BruteForceProtection::class)[0]->newInstance();
		$this->assertSame('officeOpenShare', $bruteForce->getAction());
	}
}
