<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Middleware;

use OCA\Office\AppInfo\Application;
use OCA\Office\Controller\WopiController;
use OCA\Office\Exception\WopiCallerNotAllowedException;
use OCA\Office\Service\LogThrottle;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Security\Ip\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Optionally restrict the WOPI endpoints to the editor servers' addresses
 * (app config wopi_allowlist: IPs or CIDR ranges separated by commas or spaces).
 * This is the caller check for editors that don't sign their requests.
 */
final class WopiAllowlistMiddleware extends Middleware {
	public function __construct(
		private IRequest $request,
		private IAppConfig $appConfig,
		private IFactory $ipFactory,
		private LoggerInterface $logger,
		private LogThrottle $logThrottle,
	) {
	}

	#[\Override]
	public function beforeController($controller, $methodName): void {
		if (!$controller instanceof WopiController) {
			return;
		}

		$allowlist = trim($this->appConfig->getValueString(Application::APP_ID, 'wopi_allowlist', ''));
		if ($allowlist === '') {
			return;
		}

		$remoteAddress = $this->request->getRemoteAddress();
		try {
			$address = $this->ipFactory->addressFromString($remoteAddress);
		} catch (\InvalidArgumentException) {
			throw new WopiCallerNotAllowedException('Unparsable caller address');
		}

		$entries = preg_split('/[\s,;|]+/', $allowlist, -1, PREG_SPLIT_NO_EMPTY);
		foreach ($entries === false ? [] : $entries as $entry) {
			try {
				if ($this->ipFactory->rangeFromString($entry)->contains($address)) {
					return;
				}
			} catch (\InvalidArgumentException) {
				// A typo must not open or close the allowlist silently: skip the entry and say so,
				// once an hour rather than on every WOPI request.
				if ($this->logThrottle->allows('wopi-allowlist-invalid', 3600)) {
					$this->logger->warning('Ignoring invalid wopi_allowlist entry {entry}', ['entry' => $entry]);
				}
			}
		}

		throw new WopiCallerNotAllowedException('Caller not in wopi_allowlist');
	}

	#[\Override]
	public function afterException($controller, $methodName, \Exception $exception): Response {
		if (!$exception instanceof WopiCallerNotAllowedException) {
			throw $exception;
		}

		$context = [
			'fileId' => $this->request->getParam('fileId'),
			'remoteAddress' => $this->request->getRemoteAddress(),
			'reason' => $exception->getMessage(),
		];
		if ($this->logThrottle->allows('wopi-allowlist-rejected', 300)) {
			$this->logger->warning('Rejected WOPI request for file {fileId} from {remoteAddress}: {reason} (repeats within 5 minutes are logged at debug level)', $context);
		} else {
			$this->logger->debug('Rejected WOPI request for file {fileId} from {remoteAddress}: {reason}', $context);
		}
		return new JSONResponse([], Http::STATUS_FORBIDDEN);
	}
}
