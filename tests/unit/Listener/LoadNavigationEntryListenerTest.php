<?php

declare(strict_types=1);

namespace Listener;

use OCA\Office\Listener\LoadNavigationEntryListener;
use OCP\EventDispatcher\Event;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LoadNavigationEntryListenerTest extends TestCase {
	private INavigationManager&MockObject $navigationManager;
	private LoadNavigationEntryListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->navigationManager = $this->createMock(INavigationManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/office/');
		$urlGenerator->method('imagePath')->willReturn('/apps/office/img/app.svg');

		$this->listener = new LoadNavigationEntryListener($this->navigationManager, $urlGenerator);
	}

	public function testRegistersTheUntranslatedAppEntry(): void {
		$this->navigationManager->expects($this->once())
			->method('add')
			->with($this->callback(static fn (array $entry): bool => $entry['id'] === 'office'
				&& $entry['name'] === 'Office'
				&& $entry['href'] === '/apps/office/'
				&& $entry['icon'] === '/apps/office/img/app.svg'));

		$this->listener->handle(new LoadAdditionalEntriesEvent());
	}

	public function testIgnoresOtherEvents(): void {
		$this->navigationManager->expects($this->never())->method('add');

		$this->listener->handle(new Event());
	}
}
