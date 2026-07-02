<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Share/guest token flow (ShareController::openShare + TokenManager::generateGuestToken).
 *
 * BUG found while writing this suite, documented not fixed (see hard rule 5 -
 * do not modify lib/): ShareController::openShare() guards the password
 * challenge with `$share->getPassword() !== ''`. OCP\Share\IShare::getPassword()
 * is documented (and verified live here) to return `string|null`, and a share
 * with NO password set returns null, not ''. `null !== ''` is true, so the
 * guard fires for password-LESS shares too: any unauthenticated guest hitting
 * a share link that was never password-protected gets redirected to the
 * generic Nextcloud share page instead of ever reaching the WOPI editor.
 * An authenticated visitor skips the whole branch (`!isLoggedIn()` is false)
 * and is unaffected. See wopi-spec-matrix.php row
 * SHARE-guest-blocked-by-password-check-bug and wopi-test-suite-RESULTS.md.
 */

namespace OCA\Office\Tests\Integration;

use OCA\Office\TokenManager;
use OCP\Constants;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;

class ShareGuestTokenTest extends IntegrationTestCase {
	private function createLinkShare(\OCP\Files\File $file, int $permissions): IShare {
		$shareManager = Server::get(IManager::class);
		$share = $shareManager->newShare();
		$share->setNode($file);
		$share->setShareType(IShare::TYPE_LINK);
		$share->setPermissions($permissions);
		$share->setSharedBy(static::TEST_USER);
		$share->setShareOwner(static::TEST_USER);

		return $shareManager->createShare($share);
	}

	public function testOpenShareReturns404ForUnknownToken(): void {
		$response = static::$http->get('/index.php/apps/office/open/share/this-token-does-not-exist', [
			'allow_redirects' => false,
		]);

		$this->assertSame(404, $response->getStatusCode());
	}

	public function testOpenShareRedirectsUnauthenticatedGuestEvenWithoutPassword(): void {
		$file = $this->createTestFile('share-no-password.docx');
		$share = $this->createLinkShare($file, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$this->assertNull($share->getPassword(), 'precondition: share must have no password for this to demonstrate the bug');

		$response = static::$http->get('/index.php/apps/office/open/share/' . $share->getToken() . '?guestName=Probe', [
			'allow_redirects' => false,
		]);

		// BUG (see class docblock): should be 200 with an editor TemplateResponse
		// for a passwordless share. Documenting actual current behavior.
		$this->assertSame(303, $response->getStatusCode());
		$this->assertStringContainsString('/s/' . $share->getToken(), $response->getHeaderLine('Location'));
	}

	public function testOpenShareAuthenticatedUserBypassesPasswordCheckButHitsEoDiscoveryLimitation(): void {
		$file = $this->createTestFile('share-authenticated-visit.docx');
		$share = $this->createLinkShare($file, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		// A logged-in visitor skips the buggy password branch entirely
		// (`!isLoggedIn()` is false) and reaches the discovery-dependent code -
		// which then 415s for the same EO-zero-actions reason as
		// EditorControllerTest::testOpenReturns415GivenEosCurrentZeroActionDiscovery.
		$response = static::$http->get('/index.php/apps/office/open/share/' . $share->getToken(), [
			'auth' => ['admin', 'admin'],
			'allow_redirects' => false,
		]);

		$this->assertSame(415, $response->getStatusCode());
	}

	public function testOpenShareReturns403ForUnreadableShare(): void {
		$file = $this->createTestFile('share-no-read.docx');
		// A share with neither READ nor UPDATE is nonsensical but the controller
		// guards it explicitly - exercise that guard.
		$share = $this->createLinkShare($file, Constants::PERMISSION_UPDATE);
		// Some share backends silently add READ back; only assert the guard's
		// effect if this environment actually produced a read-less share.
		if (($share->getPermissions() & Constants::PERMISSION_READ) !== 0) {
			$this->markTestSkipped('This Nextcloud version always includes PERMISSION_READ on link shares.');
		}

		// Authenticated, not guest: the password-check bug documented above
		// fires for ANY unauthenticated request to a passwordless share and
		// masks every branch after it, including this permission check - an
		// unauthenticated guest here would get 303, not 403 (verified live).
		// Only an authenticated visitor reaches the READ-permission guard at all.
		$response = static::$http->get('/index.php/apps/office/open/share/' . $share->getToken(), [
			'auth' => ['admin', 'admin'],
			'allow_redirects' => false,
		]);

		$this->assertSame(403, $response->getStatusCode());
	}

	/**
	 * Exercises the guest-token WOPI mechanics directly (TokenManager::generateGuestToken(),
	 * matching how WOPI_TEST_RESULTS.md's own Phase 1/2 methodology issued tokens
	 * directly via the mapper) so the underlying token/CheckFileInfo behavior for
	 * guests is verified over real HTTP, independent of the currently-broken
	 * ShareController HTTP entry point documented above.
	 */
	public function testGuestTokenGrantsAnonymousWopiAccess(): void {
		$file = $this->createTestFile('guest-token.docx', 'guest-accessible content');

		$tokenManager = Server::get(TokenManager::class);
		$wopi = $tokenManager->generateGuestToken(
			fileId: $file->getId(),
			ownerUid: static::TEST_USER,
			guestName: 'Integration Guest',
			canWrite: false,
			hideDownload: true,
		);

		$response = static::$http->get($this->wopiFilesUrl($file->getId(), $wopi->getToken()));

		$this->assertSame(200, $response->getStatusCode());
		$body = json_decode((string)$response->getBody(), true);
		$this->assertTrue($body['IsAnonymousUser']);
		$this->assertSame('Integration Guest', $body['UserFriendlyName']);
		$this->assertFalse($body['UserCanWrite']);
		$this->assertTrue($body['HideExportOption']);
		$this->assertTrue($body['DisablePrint']);
	}
}
