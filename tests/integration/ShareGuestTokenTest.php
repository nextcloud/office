<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Share/guest token flow (ShareController::openShare + TokenManager::generateGuestToken).
 *
 * FIXED (validator-authorized follow-up, see wopi-test-suite-FEEDBACK.md Task A):
 * ShareController::openShare() used to guard the password challenge with
 * `$share->getPassword() !== ''`. OCP\Share\IShare::getPassword() is
 * documented (and verified live here) to return `string|null`, and a share
 * with NO password set returns null, not ''. `null !== ''` is true, so the
 * guard fired for password-LESS shares too: any unauthenticated guest hitting
 * a share link that was never password-protected got redirected to the
 * generic Nextcloud share page instead of ever reaching the WOPI editor - and
 * it masked every branch after it for unauthenticated requests, including the
 * READ-permission check. Fixed in ShareController.php:79 to treat null and ''
 * both as "no password". See wopi-spec-matrix.php row
 * SHARE-guest-blocked-by-password-check-bug (now `tested`, renamed) and
 * wopi-test-suite-RESULTS.md.
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

	public function testOpenShareLetsUnauthenticatedGuestProceedWithoutPassword(): void {
		$file = $this->createTestFile('share-no-password.docx');
		$share = $this->createLinkShare($file, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);
		$this->assertNull($share->getPassword(), 'precondition: share must have no password to exercise the fixed guard');

		$response = static::$http->get('/index.php/apps/office/open/share/' . $share->getToken() . '?guestName=Probe', [
			'allow_redirects' => false,
		]);

		// Fixed behavior: an unauthenticated guest on a passwordless share is no
		// longer redirected (was 303 before the fix) - it now proceeds past the
		// password guard into the discovery-dependent editor-URL lookup, which
		// currently 415s for the same EO-zero-actions reason as
		// EditorControllerTest::testOpenReturns415GivenEosCurrentZeroActionDiscovery.
		// That 415 (not the historical 200) is the correct observable result in
		// THIS environment - it proves the guest reached real application logic
		// instead of being bounced by the password guard.
		$this->assertSame(415, $response->getStatusCode());
	}

	public function testOpenShareAuthenticatedUserBypassesPasswordCheckButHitsEoDiscoveryLimitation(): void {
		$file = $this->createTestFile('share-authenticated-visit.docx');
		$share = $this->createLinkShare($file, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		// A logged-in visitor skips the password-check branch entirely
		// (`!isLoggedIn()` is false, by design - this was already correct even
		// before the passwordless-guest fix) and reaches the discovery-dependent
		// code, which then 415s for the same EO-zero-actions reason as
		// EditorControllerTest::testOpenReturns415GivenEosCurrentZeroActionDiscovery.
		$response = static::$http->get('/index.php/apps/office/open/share/' . $share->getToken(), [
			'auth' => ['admin', 'admin'],
			'allow_redirects' => false,
		]);

		$this->assertSame(415, $response->getStatusCode());
	}

	public function testOpenShareReturns403ForUnreadableShareAsUnauthenticatedGuest(): void {
		$file = $this->createTestFile('share-no-read.docx');
		// A share with neither READ nor UPDATE is nonsensical but the controller
		// guards it explicitly - exercise that guard.
		$share = $this->createLinkShare($file, Constants::PERMISSION_UPDATE);
		// Some share backends silently add READ back; only assert the guard's
		// effect if this environment actually produced a read-less share.
		if (($share->getPermissions() & Constants::PERMISSION_READ) !== 0) {
			$this->markTestSkipped('This Nextcloud version always includes PERMISSION_READ on link shares.');
		}

		// Before the password-guard fix, this branch was unreachable for an
		// unauthenticated guest on a passwordless share (the buggy guard fired
		// first and returned 303). Now that it's fixed, an unauthenticated
		// guest reaches the READ-permission check directly - no auth needed.
		$response = static::$http->get('/index.php/apps/office/open/share/' . $share->getToken(), [
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
