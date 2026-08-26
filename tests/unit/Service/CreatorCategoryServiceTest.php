<?php

declare(strict_types=1);

namespace Service;

use OCA\Office\Service\CreatorCategoryService;
use OCP\Files\Template\ITemplateManager;
use OCP\Files\Template\TemplateFileCreator;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CreatorCategoryServiceTest extends TestCase {
	private ITemplateManager&MockObject $templateManager;
	private CreatorCategoryService $service;

	/** @var array<string, mixed> What the cache holds, keyed as the service keys it */
	private array $cached = [];

	private int $cacheReads = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->templateManager = $this->createMock(ITemplateManager::class);
		$this->service = $this->service('alice', 'en');
	}

	/**
	 * A service for one user and language, sharing $this->cached with every
	 * other one built here — so a second service sees what the first wrote,
	 * the way a second request would.
	 */
	private function service(?string $uid, string $language): CreatorCategoryService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('getLanguageCode')->willReturn($language);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn((string)$uid);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($uid === null ? null : $user);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function (string $key): mixed {
			$this->cacheReads++;
			return $this->cached[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl = 0): bool {
			$this->cached[$key] = $value;
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		return new CreatorCategoryService($l10n, $this->templateManager, $userSession, $cacheFactory);
	}

	private function creator(string $app, string $label, string $extension, string ...$mimetypes): TemplateFileCreator {
		$creator = new TemplateFileCreator($app, $label, $extension);
		foreach ($mimetypes as $mimetype) {
			$creator->addMimetype($mimetype);
		}
		return $creator;
	}

	public function testCategoryIdMapsAKnownMimetype(): void {
		$creator = $this->creator('richdocuments', 'Spreadsheet', '.ods', 'application/vnd.ms-excel');

		$this->assertSame('spreadsheets', $this->service->categoryId($creator));
	}

	/**
	 * The id lands in the URL and in the app menu, so it must not move when the
	 * admin flips doc_format — which swaps the creator's extension and mimetypes.
	 */
	public function testCategoryIdIsTheSameForOdfAndOoxml(): void {
		$odf = $this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text');
		$ooxml = $this->creator('richdocuments', 'Document', '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

		$this->assertSame($this->service->categoryId($odf), $this->service->categoryId($ooxml));
	}

	public function testCategoryIdFallsBackToAppAndExtension(): void {
		$creator = $this->creator('customapp', 'Custom', '.xyz', 'application/x-custom');

		$this->assertSame('customapp-xyz', $this->service->categoryId($creator));
	}

	public function testCategoryLabelIsTheCategoryName(): void {
		$creator = $this->creator('richdocuments', 'Presentation', '.odp', 'application/vnd.oasis.opendocument.presentation');

		$this->assertSame('Presentations', $this->service->categoryLabel($creator));
	}

	public function testCategoryLabelFallsBackToTheCreatorLabel(): void {
		$creator = $this->creator('customapp', 'Custom', '.xyz', 'application/x-custom');

		$this->assertSame('Custom', $this->service->categoryLabel($creator));
	}

	public function testCategoryColorIsTheCategoryColor(): void {
		$creator = $this->creator('richdocuments', 'Spreadsheet', '.ods', 'application/vnd.ms-excel');

		$this->assertSame('#9abd4e', $this->service->categoryColor($creator));
	}

	public function testCategoryColorIsNullForAnUnknownCategory(): void {
		$creator = $this->creator('customapp', 'Custom', '.xyz', 'application/x-custom');

		$this->assertNull($this->service->categoryColor($creator));
	}

	public function testCategoryMimetypesCoverBothFormatsOfTheCategory(): void {
		$creator = $this->creator('richdocuments', 'Document', '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

		$mimetypes = $this->service->categoryMimetypes($creator);

		$this->assertContains('application/vnd.oasis.opendocument.text', $mimetypes);
		$this->assertContains('application/msword', $mimetypes);
		$this->assertNotContains('application/vnd.ms-excel', $mimetypes);
	}

	public function testCategoryMimetypesKeepWhatTheCreatorAdvertises(): void {
		$creator = $this->creator('customapp', 'Custom', '.xyz', 'application/x-custom');

		$this->assertSame(['application/x-custom'], $this->service->categoryMimetypes($creator));
	}

	public function testCategoryMimetypesAreNotDuplicated(): void {
		$creator = $this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text');

		$mimetypes = $this->service->categoryMimetypes($creator);

		$this->assertSame(array_values(array_unique($mimetypes)), $mimetypes);
	}

	public function testListCategoriesDescribesEveryRegisteredCreator(): void {
		$this->templateManager->method('listCreators')->willReturn([
			$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text'),
			$this->creator('richdocuments', 'Spreadsheet', '.ods', 'application/vnd.oasis.opendocument.spreadsheet'),
		]);

		$categories = $this->service->listCategories();

		$this->assertCount(2, $categories);
		$this->assertSame('richdocuments', $categories[0]['app']);
		$this->assertSame('.odt', $categories[0]['extension']);
		$this->assertSame('documents', $categories[0]['id']);
		$this->assertSame('Documents', $categories[0]['label']);
		$this->assertContains('application/msword', $categories[0]['mimetypes']);
		$this->assertSame('spreadsheets', $categories[1]['id']);
	}

	public function testListCategoriesIsEmptyWithoutCreators(): void {
		$this->templateManager->method('listCreators')->willReturn([]);

		$this->assertSame([], $this->service->listCategories());
	}

	public function testListCreatorCategoriesDescribesWhatTheAppMenuNeeds(): void {
		$document = $this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text');
		$document->setOrder(7);
		$document->setIconSvgInline('<svg xmlns="http://www.w3.org/2000/svg" />');
		$this->templateManager->method('listCreators')->willReturn([$document]);

		$categories = $this->service->listCreatorCategories();

		$this->assertSame('documents', $categories[0]['id']);
		$this->assertSame('Documents', $categories[0]['label']);
		$this->assertSame('#49abea', $categories[0]['color']);
		$this->assertSame(7, $categories[0]['order']);
		$this->assertSame('<svg xmlns="http://www.w3.org/2000/svg" />', $categories[0]['iconSvgInline']);
	}

	/**
	 * Registering the creators is what the cache is there to skip: a second
	 * request for the same user must not run it again.
	 */
	public function testServesASecondRequestFromTheCache(): void {
		$this->templateManager->expects($this->once())
			->method('listCreators')
			->willReturn([$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text')]);

		$first = $this->service->listCategories();
		$second = $this->service('alice', 'en')->listCategories();

		$this->assertSame($first, $second);
	}

	public function testDoesNotServeOneUserFromAnotherUsersCache(): void {
		$this->templateManager->expects($this->exactly(2))
			->method('listCreators')
			->willReturn([$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text')]);

		$this->service->listCategories();
		$this->service('bob', 'en')->listCategories();
	}

	/**
	 * Labels are translated before they are cached, so a user reading in another
	 * language must not be served the first user's language.
	 */
	public function testDoesNotServeOneLanguageFromAnother(): void {
		$this->templateManager->expects($this->exactly(2))
			->method('listCreators')
			->willReturn([$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text')]);

		$this->service->listCategories();
		$this->service('alice', 'de')->listCategories();
	}

	/**
	 * Nothing identifies the result of a request without a user, so it is
	 * computed every time rather than shared with the next one.
	 */
	public function testDoesNotCacheWithoutAUser(): void {
		$this->templateManager->expects($this->exactly(2))
			->method('listCreators')
			->willReturn([$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text')]);

		$this->service(null, 'en')->listCategories();
		$this->service(null, 'en')->listCategories();
		$this->assertSame([], $this->cached);
	}

	/**
	 * The cached value outlives the request that wrote it, so what a request
	 * builds from it — the app menu's URLs and icon paths above all — must not
	 * be part of it.
	 */
	public function testCachesNothingRequestScoped(): void {
		$this->templateManager->method('listCreators')->willReturn([
			$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text'),
		]);

		$this->service->listCategories();

		$this->assertSame(
			['app', 'extension', 'id', 'label', 'mimetypes', 'color', 'order', 'iconSvgInline'],
			array_keys($this->cached['alice-en'][0]),
		);
	}

	public function testAsksTheCacheOnlyOncePerRequest(): void {
		$this->templateManager->method('listCreators')->willReturn([
			$this->creator('richdocuments', 'Document', '.odt', 'application/vnd.oasis.opendocument.text'),
		]);

		$this->service->listCategories();
		$this->service->listCreatorCategories();

		$this->assertSame(1, $this->cacheReads);
	}
}
