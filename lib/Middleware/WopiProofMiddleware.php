<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Middleware;

use OCA\Office\Controller\WopiController;
use OCA\Office\Exception\WopiProofException;
use OCA\Office\Service\DiscoveryService;
use OCA\Office\Service\LogThrottle;
use OCA\Office\Service\WopiProofKeys;
use OCA\Office\Service\WopiProofVerifier;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * WOPI endpoints are public and authorised by the access token alone. When the editor
 * publishes proof keys, also require its signature, so a token copied out of a browser
 * (e.g. on a "hide download" share) can't be used to fetch or overwrite the file directly.
 * Editors that don't sign are let through; the admin page warns about it.
 */
final class WopiProofMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
		private DiscoveryService $discoveryService,
		private WopiProofVerifier $verifier,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private LogThrottle $logThrottle,
	) {
	}

	#[\Override]
	public function beforeController($controller, $methodName): void {
		if (!$controller instanceof WopiController) {
			return;
		}

		$keys = $this->discoveryService->getProofKeys();
		if ($keys === null && $this->request->getHeader('X-WOPI-Proof') !== '') {
			// The editor may have started signing since the keys were last read.
			$keys = $this->discoveryService->refreshProofKeys();
		}
		if ($keys === null) {
			return;
		}

		if (!$this->verifier->isFresh($this->request->getHeader('X-WOPI-TimeStamp'))) {
			throw new WopiProofException('Missing or expired X-WOPI-TimeStamp');
		}

		if ($this->isSignedWith($keys)) {
			return;
		}

		// Keys regenerated on the editor (e.g. a recreated container) aren't known yet.
		$current = $this->discoveryService->refreshProofKeys();
		if ($current !== null && $current != $keys && $this->isSignedWith($current)) {
			return;
		}

		throw new WopiProofException('Invalid X-WOPI-Proof');
	}

	private function isSignedWith(WopiProofKeys $keys): bool {
		// The editor signs the URL it called: Nextcloud's own absolute URL, or the
		// callback_url form of it when the editor reaches Nextcloud by another address.
		$url = $this->urlGenerator->getAbsoluteURL($this->request->getRequestUri());
		$accessToken = (string)$this->request->getParam('access_token', '');
		foreach (array_unique([$url, $this->discoveryService->editorReachableUrl($url)]) as $signedUrl) {
			if ($this->verifier->isValid(
				$keys,
				$accessToken,
				$signedUrl,
				$this->request->getHeader('X-WOPI-TimeStamp'),
				$this->request->getHeader('X-WOPI-Proof'),
				$this->request->getHeader('X-WOPI-ProofOld'),
			)) {
				return true;
			}
		}

		return false;
	}

	#[\Override]
	public function afterException($controller, $methodName, \Exception $exception): Response {
		if (!$exception instanceof WopiProofException) {
			throw $exception;
		}

		$context = [
			'fileId' => $this->request->getParam('fileId'),
			'reason' => $exception->getMessage(),
			'remoteAddress' => $this->request->getRemoteAddress(),
		];
		if ($this->logThrottle->allows('wopi-proof-rejected', 300)) {
			$this->logger->warning('Rejected WOPI request for file {fileId} from {remoteAddress}: {reason} (repeats within 5 minutes are logged at debug level)', $context);
		} else {
			$this->logger->debug('Rejected WOPI request for file {fileId} from {remoteAddress}: {reason}', $context);
		}
		return new JSONResponse([], Http::STATUS_FORBIDDEN);
	}
}
