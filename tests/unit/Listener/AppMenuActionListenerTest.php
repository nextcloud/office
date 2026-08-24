<?php

declare(strict_types=1);

namespace Listener;

use OCA\Office\Listener\AppMenuActionListener;
use OCA\Office\Service\CreatorCategoryService;
use OCP\EventDispatcher\Event;
use OCP\Files\Template\ITemplateManager;
use OCP\Files\Template\TemplateFileCreator;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AppMenuActionListenerTest extends TestCase {
	private ITemplateManager&MockObject $templateManager;
	private INavigationManager&MockObject $navigationManager;
	private IUserSession&MockObject $userSession;
	private AppMenuActionListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->templateManager = $this->createMock(ITemplateManager::class);
		$this->navigationManager = $this->createMock(INavigationManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('isLoggedIn')->willReturn(true);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')
			->willReturnCallback(static fn (string $route, array $parameters = []): string
				=> '/apps/office/' . ($parameters['path'] ?? ''));
		$urlGenerator->method('imagePath')
			->willReturnCallback(static fn (string $app, string $image): string => "/apps/$app/img/$image");

		$this->listener = new AppMenuActionListener(
			$this->categoryService($l10n, $this->userSession),
			$this->navigationManager,
			$urlGenerator,
			$this->userSession,
		);
	}

	/**
	 * The service with a cache that never hits, so every test here describes the
	 * creators it registers itself. What the cache holds is
	 * CreatorCategoryServiceTest's subject.
	 */
	private function categoryService(IL10N $l10n, IUserSession $userSession): CreatorCategoryService {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new CreatorCategoryService($l10n, $this->templateManager, $userSession, $cacheFactory);
	}

	private function creator(string $app, string $label, string $extension, string $mimetype, ?string $icon = null): TemplateFileCreator {
		$creator = new TemplateFileCreator($app, $label, $extension);
		$creator->addMimetype($mimetype);
		if ($icon !== null) {
			$creator->setIconSvgInline($icon);
		}
		return $creator;
	}

	/**
	 * @return list<array<string, mixed>> The entries the listener registered
	 */
	private function handleAndCollect(): array {
		$entries = [];
		$this->navigationManager->method('add')
			->willReturnCallback(function (array|callable $entry) use (&$entries): void {
				$entries[] = $entry;
			});

		$this->listener->handle(new LoadAdditionalEntriesEvent());

		return $entries;
	}

	public function testRegistersOneActionPerCreator(): void {
		$this->templateManager->method('listCreators')->willReturn([
			$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text'),
			$this->creator('richdocuments', 'Spreadsheet', '.ods', 'application/vnd.oasis.opendocument.spreadsheet'),
		]);

		$entries = $this->handleAndCollect();

		$this->assertCount(2, $entries);
		$this->assertSame('office-documents', $entries[0]['id']);
		$this->assertSame(INavigationManager::TYPE_ACTION, $entries[0]['type']);
		$this->assertSame('Documents', $entries[0]['name']);
		$this->assertSame('/apps/office/documents', $entries[0]['href']);
		$this->assertSame('office-spreadsheets', $entries[1]['id']);
		$this->assertSame('/apps/office/spreadsheets', $entries[1]['href']);
	}

	public function testMarksTheActionWithTheCategoryColor(): void {
		$this->templateManager->method('listCreators')->willReturn([
			$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text'),
		]);

		$entries = $this->handleAndCollect();

		$this->assertSame('#49abea', $entries[0]['color']);
	}

	/**
	 * The app menu only renders the create indicator when a color is set, so a
	 * category outside the static map must not carry the key at all.
	 */
	public function testLeavesOutTheColorOfAnUnknownCategory(): void {
		$this->templateManager->method('listCreators')->willReturn([
			$this->creator('customapp', 'Custom', '.xyz', 'application/x-custom'),
		]);

		$entries = $this->handleAndCollect();

		$this->assertArrayNotHasKey('color', $entries[0]);
	}

	public function testUsesTheCreatorIconAsDataUri(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" />';
		$this->templateManager->method('listCreators')->willReturn([
			$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text', $svg),
		]);

		$entries = $this->handleAndCollect();

		$this->assertSame('data:image/svg+xml;base64,' . base64_encode($svg), $entries[0]['icon']);
	}

	public function testFallsBackToTheAppIconWithoutACreatorIcon(): void {
		$this->templateManager->method('listCreators')->willReturn([
			$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text'),
		]);

		$entries = $this->handleAndCollect();

		$this->assertSame('/apps/office/img/app.svg', $entries[0]['icon']);
	}

	/**
	 * Both entries would resolve to the same category URL, and the office app
	 * shows the first creator for it — so only that one gets an action.
	 */
	public function testRegistersOneActionPerCategory(): void {
		$this->templateManager->method('listCreators')->willReturn([
			$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text'),
			$this->creator('otheroffice', 'Text', '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
		]);

		$entries = $this->handleAndCollect();

		$this->assertCount(1, $entries);
		$this->assertSame('office-documents', $entries[0]['id']);
	}

	public function testRegistersNothingWithoutCreators(): void {
		$this->templateManager->method('listCreators')->willReturn([]);
		$this->navigationManager->expects($this->never())->method('add');

		$this->listener->handle(new LoadAdditionalEntriesEvent());
	}

	public function testIgnoresGuests(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('isLoggedIn')->willReturn(false);
		$userSession->method('getUser')->willReturn(null);
		$l10n = $this->createMock(IL10N::class);
		$listener = new AppMenuActionListener(
			$this->categoryService($l10n, $userSession),
			$this->navigationManager,
			$this->createMock(IURLGenerator::class),
			$userSession,
		);
		$this->templateManager->expects($this->never())->method('listCreators');
		$this->navigationManager->expects($this->never())->method('add');

		$listener->handle(new LoadAdditionalEntriesEvent());
	}

	public function testIgnoresOtherEvents(): void {
		$this->navigationManager->expects($this->never())->method('add');

		$this->listener->handle(new Event());
	}

	public function testKeepsTheCreatorOrder(): void {
		$document = $this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text');
		$document->setOrder(7);
		$this->templateManager->method('listCreators')->willReturn([$document]);

		$entries = $this->handleAndCollect();

		$this->assertSame(7, $entries[0]['order']);
	}
}
