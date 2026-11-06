<?php

declare(strict_types=1);

namespace Listener;

use OCA\Office\Listener\LoadNavigationEntryListener;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LoadNavigationEntryListenerTest extends TestCase {
	private IAppManager&MockObject $appManager;
	private INavigationManager&MockObject $navigationManager;
	private LoadNavigationEntryListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->appManager = $this->createMock(IAppManager::class);
		$this->navigationManager = $this->createMock(INavigationManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->with('office.page.index')->willReturn('/apps/office/');
		$urlGenerator->method('imagePath')->with('office', 'app.svg')->willReturn('/apps/office/img/app.svg');

		$this->listener = new LoadNavigationEntryListener($this->appManager, $this->navigationManager, $urlGenerator);
	}

	private function registeredEntry(): ?array {
		$entry = null;
		$this->navigationManager->expects($this->once())
			->method('add')
			->willReturnCallback(static function (\Closure $closure) use (&$entry): void {
				$entry = $closure();
			});

		$this->listener->handle(new LoadAdditionalEntriesEvent());

		return $entry;
	}

	public function testRegistersTheUntranslatedAppEntry(): void {
		$this->appManager->method('isEnabledForUser')->with('office')->willReturn(true);

		$entry = $this->registeredEntry();

		$this->assertSame('office', $entry['id']);
		$this->assertSame('office', $entry['app']);
		$this->assertSame('Office', $entry['name']);
		$this->assertSame('/apps/office/', $entry['href']);
		$this->assertSame('/apps/office/img/app.svg', $entry['icon']);
	}

	public function testSkipsEntryForUsersWithoutAccess(): void {
		$this->appManager->method('isEnabledForUser')->with('office')->willReturn(false);

		$this->assertNull($this->registeredEntry());
	}

	public function testIgnoresOtherEvents(): void {
		$this->navigationManager->expects($this->never())->method('add');

		$this->listener->handle(new Event());
	}
}
