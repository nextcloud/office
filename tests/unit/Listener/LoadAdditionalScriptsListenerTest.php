<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Office\Tests\Unit\Listener;

use OCA\Office\Listener\LoadAdditionalScriptsListener;
use OCA\Office\Service\DiscoveryService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Collaboration\Resources\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Whether the listener loads the file action is observed through the initial state it
 * provides first; Util::addInitScript() needs a running server and isn't reached here.
 */
final class LoadAdditionalScriptsListenerTest extends TestCase {
	private function loadsFileAction(Event $event): bool {
		$initialState = $this->createMock(IInitialState::class);
		$loaded = false;
		$initialState->method('provideInitialState')->willReturnCallback(static function () use (&$loaded): void {
			$loaded = true;
		});
		$discovery = $this->createMock(DiscoveryService::class);
		$discovery->method('getSupportedMimeTypes')->willReturn([]);

		try {
			(new LoadAdditionalScriptsListener($discovery, $initialState))->handle($event);
		} catch (\Error) {
			// Util::addInitScript() without a server: the decision was already made.
		}

		return $loaded;
	}

	// PublicTemplateResponse's constructor needs a running server, so it is mocked.
	private function publicPage(string $app, string $template): BeforeTemplateRenderedEvent {
		$response = $this->createMock(PublicTemplateResponse::class);
		$response->method('getApp')->willReturn($app);
		$response->method('getTemplateName')->willReturn($template);

		return new BeforeTemplateRenderedEvent(false, $response);
	}

	public function testFilesApp(): void {
		$this->assertTrue($this->loadsFileAction(new LoadAdditionalScriptsEvent()));
	}

	public function testPublicSharePage(): void {
		$this->assertTrue($this->loadsFileAction($this->publicPage('files', 'index')));
	}

	public function testNotOnOtherPublicPages(): void {
		$this->assertFalse($this->loadsFileAction($this->publicPage('files_sharing', 'authenticate')));
		$this->assertFalse($this->loadsFileAction($this->publicPage('files', 'other')));
		$this->assertFalse($this->loadsFileAction($this->publicPage('other_app', 'index')));
	}

	public function testNotTwiceOnTheLoggedInFilesPage(): void {
		// The logged-in Files page already gets it through LoadAdditionalScriptsEvent.
		$this->assertFalse($this->loadsFileAction(new BeforeTemplateRenderedEvent(true, new TemplateResponse('files', 'index'))));
	}

	public function testNotOnUnrelatedEvents(): void {
		$this->assertFalse($this->loadsFileAction(new Event()));
	}
}
