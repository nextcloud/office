<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Controller;

use OCA\Office\AppInfo\Application;
use OCA\Office\Http\EditorPagePolicy;
use OCA\Office\Service\DiscoveryService;
use OCA\Office\TokenManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

class ShareController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IShareManager $shareManager,
		private ISession $session,
		private IUserSession $userSession,
		private TokenManager $tokenManager,
		private DiscoveryService $discoveryService,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Open a file in the WOPI editor via a public share link.
	 *
	 * For folder shares, pass either $fileId or $path to identify the target file
	 * within the shared folder.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/open/share/{shareToken}')]
	public function openShare(
		string $shareToken,
		?int $fileId = null,
		?string $path = null,
		?string $guestName = null,
	): TemplateResponse|JSONResponse|RedirectResponse {
		try {
			$share = $this->shareManager->getShareByToken($shareToken);
		} catch (ShareNotFound $e) {
			$this->logger->debug('Share token not found: ' . $e->getMessage());
			return new JSONResponse(['error' => 'Share not found'], Http::STATUS_NOT_FOUND);
		}

		// Password-protected share: NC core sets 'public_link_authenticated' in the session
		// once the user has entered the password at /s/{token}. Check both legacy (string)
		// and current (array of share IDs) formats, matching richdocuments' pattern.
		// getPassword() returns null (not '') for a share with no password at all - both
		// must be treated as "no password", or every passwordless share incorrectly
		// redirects unauthenticated guests away instead of letting them through.
		// (array) turns the legacy single-ID string into a one-element list and null into [].
		$passwordPending = $share->getPassword() !== null && $share->getPassword() !== ''
			&& !in_array($share->getId(), (array)$this->session->get('public_link_authenticated'), true);

		// Logged-in users who can reach the file through their own folder skip the password below;
		// everyone whose only access is this link has to pass it.
		if ($passwordPending && !$this->userSession->isLoggedIn()) {
			return $this->passwordChallenge($shareToken);
		}

		// Below, a logged-in user may still be pending the password: until it's entered, every
		// failure leads to the challenge, so probing ?path= or ?fileId= reveals nothing.
		if (($share->getPermissions() & \OCP\Constants::PERMISSION_READ) === 0) {
			return $this->refuse($passwordPending, $shareToken, new JSONResponse(['error' => 'Share is not readable'], Http::STATUS_FORBIDDEN));
		}

		try {
			$file = $this->resolveFile($share, $fileId, $path);
		} catch (NotFoundException $e) {
			return $this->refuse($passwordPending, $shareToken, new JSONResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND));
		} catch (NotPermittedException $e) {
			return $this->refuse($passwordPending, $shareToken, new JSONResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN));
		}

		if (!$this->discoveryService->isAvailable()) {
			return $this->refuse($passwordPending, $shareToken, new JSONResponse(['error' => 'Editor server unavailable'], Http::STATUS_SERVICE_UNAVAILABLE));
		}

		$extension = pathinfo($file->getName(), PATHINFO_EXTENSION);
		$urlsrc = $this->discoveryService->getUrlSrc($extension, 'edit')
			?? $this->discoveryService->getUrlSrc($extension, 'view');

		if ($urlsrc === null) {
			return $this->refuse($passwordPending, $shareToken, new JSONResponse(
				['error' => 'File type not supported by the editor'],
				Http::STATUS_UNSUPPORTED_MEDIA_TYPE,
			));
		}

		$canWrite = (bool)($share->getPermissions() & \OCP\Constants::PERMISSION_UPDATE);
		$ownerUid = $share->getShareOwner();

		try {
			$wopi = null;
			$user = $this->userSession->getUser();
			if ($user !== null) {
				// Authenticated user visiting a share link: issue a full user token via their
				// own folder. hideDownload is intentionally not applied — the share's download
				// restriction targets unauthenticated third parties, not collaborators with
				// direct NC access.
				try {
					$wopi = $this->tokenManager->generateToken($file->getId());
				} catch (NotPermittedException) {
					// The file isn't in their own folder: the link is their only access, so they
					// get the same share-scoped token as a guest, under their own name.
				}
			}

			if ($wopi === null) {
				if ($passwordPending) {
					return $this->passwordChallenge($shareToken);
				}

				$hideDownload = $share->getHideDownload();

				// Sanitize guestName: strip control chars, cap at 64 chars, default to 'Guest'.
				// Authenticated user's display name overrides guestName param to prevent spoofing.
				$guestName = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $guestName ?? ''));
				$guestName = mb_substr($guestName, 0, 64);
				$displayName = $user?->getDisplayName() ?? ($guestName !== '' ? $guestName : 'Guest');

				$wopi = $this->tokenManager->generateGuestToken(
					fileId: $file->getId(),
					ownerUid: $ownerUid,
					guestName: $displayName,
					canWrite: $canWrite,
					hideDownload: $hideDownload,
				);
			}
		} catch (NotPermittedException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'File not accessible'], Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$wopiSrc = $this->urlGenerator->linkToRouteAbsolute(
			'office.wopi.checkFileInfo',
			['fileId' => $file->getId()],
		);

		$editorUrl = $this->discoveryService->buildEditorUrl($urlsrc, $wopiSrc, $wopi->getToken());

		try {
			$policy = EditorPagePolicy::forEditorUrl($editorUrl);
		} catch (\InvalidArgumentException $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = new TemplateResponse(Application::APP_ID, 'editor', [], 'base');
		$response->setParams([
			'editorUrl' => $editorUrl,
			// WOPI access_token_ttl: absolute expiry in milliseconds since the epoch.
			'accessTokenTtl' => $wopi->getExpiry() * 1000,
			'postMessageOrigin' => $wopi->getServerHost(),
			'fileName' => $file->getName(),
		]);
		$response->setContentSecurityPolicy($policy);
		return $response;
	}

	/**
	 * Send the visitor to the share page to enter the password. After authenticating
	 * there, they reopen the file action.
	 */
	private function passwordChallenge(string $shareToken): RedirectResponse {
		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'files_sharing.sharecontroller.showShare',
			['token' => $shareToken],
		));
	}

	private function refuse(bool $passwordPending, string $shareToken, JSONResponse $error): JSONResponse|RedirectResponse {
		return $passwordPending ? $this->passwordChallenge($shareToken) : $error;
	}

	/**
	 * Resolve the target file from a share.
	 *
	 * For file shares: returns the shared node directly.
	 * For folder shares: resolves by $fileId or $path within the shared folder.
	 * NC throws NotFoundException on path traversal attempts — no extra guard needed.
	 *
	 * @throws NotFoundException
	 * @throws NotPermittedException
	 */
	private function resolveFile(IShare $share, ?int $fileId, ?string $path): File {
		$node = $share->getNode();

		if ($node instanceof File) {
			return $node;
		}

		if (!$node instanceof Folder) {
			throw new NotFoundException('Share points to an unsupported node type');
		}

		if ($path !== null) {
			$resolved = $node->get($path);
		} elseif ($fileId !== null) {
			// getFirstNodeById scoped to $node — cannot escape the shared folder boundary.
			$resolved = $node->getFirstNodeById($fileId);
		} else {
			throw new NotFoundException('Folder share requires fileId or path parameter');
		}

		if (!$resolved instanceof File) {
			throw new NotFoundException('Resolved node is not a file');
		}

		return $resolved;
	}
}
