<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Listener;

use OCA\Office\AppInfo\Application;
use OCA\Office\Service\DiscoveryService;
use OCP\Collaboration\Resources\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IInitialStateService;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadAdditionalScriptsListener implements IEventListener {
	public function __construct(
		private DiscoveryService $discoveryService,
		private IInitialStateService $initialState,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}

		try {
			$mimes = $this->discoveryService->getSupportedMimeTypes();
		} catch (\Throwable) {
			$mimes = [];
		}

		$this->initialState->provideInitialState(Application::APP_ID, 'supported-mimes', $mimes);

		Util::addInitScript(Application::APP_ID, 'office-file-actions');
	}
}
