<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Listener;

use OCA\Office\AppInfo\Application;
use OCA\Office\Service\DiscoveryService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Collaboration\Resources\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Adds the "Open in Office" file action to the Files app, both for users and on public
 * share pages (which Files renders as a public "files/index" page).
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent|BeforeTemplateRenderedEvent>
 */
final class LoadAdditionalScriptsListener implements IEventListener {
	public function __construct(
		private DiscoveryService $discoveryService,
		private IInitialState $initialState,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent && !$this->isPublicSharePage($event)) {
			return;
		}

		try {
			$mimes = $this->discoveryService->getSupportedMimeTypes();
		} catch (\Throwable) {
			$mimes = [];
		}

		$this->initialState->provideInitialState('supported-mimes', $mimes);

		Util::addInitScript(Application::APP_ID, 'office-file-actions');
	}

	private function isPublicSharePage(Event $event): bool {
		if (!$event instanceof BeforeTemplateRenderedEvent) {
			return false;
		}

		$response = $event->getResponse();
		return $response instanceof PublicTemplateResponse
			&& $response->getApp() === 'files'
			&& $response->getTemplateName() === 'index';
	}
}
