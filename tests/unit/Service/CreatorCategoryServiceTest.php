<?php

declare(strict_types=1);

namespace Service;

use OCA\Office\Service\CreatorCategoryService;
use OCP\Files\Template\ITemplateManager;
use OCP\Files\Template\TemplateFileCreator;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CreatorCategoryServiceTest extends TestCase {
	private ITemplateManager&MockObject $templateManager;
	private CreatorCategoryService $service;

	protected function setUp(): void {
		parent::setUp();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->templateManager = $this->createMock(ITemplateManager::class);
		$this->service = new CreatorCategoryService($l10n, $this->templateManager);
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
}
