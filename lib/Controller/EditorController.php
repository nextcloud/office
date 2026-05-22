<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Controller;

use OCA\Office\AppInfo\Application;
use OCA\Office\Service\DiscoveryService;
use OCA\Office\TokenManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class EditorController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private TokenManager $tokenManager,
		private DiscoveryService $discoveryService,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Open a file in the WOPI editor.
	 *
	 * Returns an HTML page that embeds the editor in a full-screen iframe.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/open')]
	public function open(int $fileId): TemplateResponse|JSONResponse {
		try {
			$userFolder = $this->rootFolder->getUserFolder((string)$this->userId);
			$file = $userFolder->getFirstNodeById($fileId);

			if (!$file instanceof File) {
				return new JSONResponse(['error' => 'File not found'], \OCP\AppFramework\Http::STATUS_NOT_FOUND);
			}

			$extension = pathinfo($file->getName(), PATHINFO_EXTENSION);
			$urlsrc = $this->discoveryService->getUrlSrc($extension, 'edit')
				?? $this->discoveryService->getUrlSrc($extension, 'view');

			if ($urlsrc === null) {
				return new JSONResponse(
					['error' => 'File type not supported by the editor'],
					\OCP\AppFramework\Http::STATUS_UNSUPPORTED_MEDIA_TYPE
				);
			}

			$wopi = $this->tokenManager->generateToken($fileId);

			$wopiSrc = $this->urlGenerator->linkToRouteAbsolute(
				'office.wopi.checkFileInfo',
				['fileId' => $fileId]
			);

			$editorUrl = $this->discoveryService->buildEditorUrl($urlsrc, $wopiSrc, $wopi->getToken());

		} catch (NotFoundException|NotPermittedException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'File not accessible'], \OCP\AppFramework\Http::STATUS_FORBIDDEN);
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Internal error'], \OCP\AppFramework\Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$response = new TemplateResponse(Application::APP_ID, 'editor', [], 'blank');
		$response->setParams([
			'editorUrl' => $editorUrl,
			'postMessageOrigin' => $wopi->getServerHost(),
			'fileName' => $file->getName(),
		]);
		return $response;
	}
}
