<?php

declare(strict_types=1);

namespace OCA\Office\Service;

use OCP\Files\Template\ITemplateManager;
use OCP\Files\Template\TemplateFileCreator;
use OCP\IL10N;

/**
 * Maps the registered template creators onto the categories the office app
 * presents them as.
 *
 * A category id is the creator's identity in URLs (/apps/office/<id>) and in the
 * app menu, so it stays stable when an admin switches doc_format, which changes
 * a creator's extension and mimetypes.
 *
 * @psalm-type OfficeCategory = array{app: string, extension: string, id: string, label: string, mimetypes: list<string>}
 */
final class CreatorCategoryService {
	private const array MIME_CATEGORIES = [
		'application/vnd.oasis.opendocument.text' => 'documents',
		'application/vnd.oasis.opendocument.text-template' => 'documents',
		'application/msword' => 'documents',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'documents',
		'application/vnd.oasis.opendocument.spreadsheet' => 'spreadsheets',
		'application/vnd.oasis.opendocument.spreadsheet-template' => 'spreadsheets',
		'application/vnd.ms-excel' => 'spreadsheets',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'spreadsheets',
		'application/vnd.oasis.opendocument.presentation' => 'presentations',
		'application/vnd.oasis.opendocument.presentation-template' => 'presentations',
		'application/vnd.ms-powerpoint' => 'presentations',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'presentations',
		'application/vnd.oasis.opendocument.graphics' => 'diagrams',
		'application/vnd.oasis.opendocument.graphics-template' => 'diagrams',
	];

	/** @psalm-suppress PossiblyUnusedMethod Constructed by the DI container */
	public function __construct(
		private IL10N $l10n,
		private ITemplateManager $templateManager,
	) {
	}

	/**
	 * Every registered creator with the category it belongs to. The frontend
	 * joins these onto the creators it gets from the templates API by app and
	 * extension.
	 *
	 * @return list<OfficeCategory>
	 */
	public function listCategories(): array {
		$categories = [];
		foreach ($this->listCreators() as $creator) {
			$categories[] = [
				'app' => $creator->getAppId(),
				'extension' => $this->describe($creator)['extension'],
				'id' => $this->categoryId($creator),
				'label' => $this->categoryLabel($creator),
				'mimetypes' => $this->categoryMimetypes($creator),
			];
		}
		return $categories;
	}

	/**
	 * @return list<TemplateFileCreator> Registered creators, ordered by the order they declared
	 */
	public function listCreators(): array {
		/** @var list<TemplateFileCreator> $creators ITemplateManager::listCreators() is untyped */
		$creators = $this->templateManager->listCreators();
		return $creators;
	}

	/**
	 * Creators the static map does not cover fall back to app and extension:
	 * locale-independent and stable, but tied to the create format.
	 */
	public function categoryId(TemplateFileCreator $creator): string {
		$category = $this->category($creator);
		if ($category !== null) {
			return $category;
		}
		return $creator->getAppId() . '-' . ltrim($this->describe($creator)['extension'], '.');
	}

	public function categoryLabel(TemplateFileCreator $creator): string {
		return match ($this->category($creator)) {
			'documents' => $this->l10n->t('Documents'),
			'spreadsheets' => $this->l10n->t('Spreadsheets'),
			'presentations' => $this->l10n->t('Presentations'),
			'diagrams' => $this->l10n->t('Diagrams'),
			default => $this->describe($creator)['label'],
		};
	}

	public function categoryColor(TemplateFileCreator $creator): ?string {
		return match ($this->category($creator)) {
			'documents' => '#49abea',
			'spreadsheets' => '#9abd4e',
			'presentations' => '#f18500',
			'diagrams' => '#d93f0b',
			default => null,
		};
	}

	/**
	 * Both the ODF and the OOXML mimetypes of the category, so a category lists
	 * every file it can open regardless of the configured create format, plus
	 * whatever the creator advertises beyond the static map.
	 *
	 * @return list<string>
	 */
	public function categoryMimetypes(TemplateFileCreator $creator): array {
		$category = $this->category($creator);
		$mimetypes = $category === null
			? []
			: array_keys(self::MIME_CATEGORIES, $category, true);
		return array_values(array_unique([...$mimetypes, ...$this->describe($creator)['mimetypes']]));
	}

	private function category(TemplateFileCreator $creator): ?string {
		foreach ($this->describe($creator)['mimetypes'] as $mime) {
			if (isset(self::MIME_CATEGORIES[$mime])) {
				return self::MIME_CATEGORIES[$mime];
			}
		}
		return null;
	}

	/**
	 * TemplateFileCreator exposes neither its action name nor its extension
	 * through a getter, and getMimetypes() is untyped; the serialized form
	 * carries all three with types.
	 *
	 * @return array{app: string, label: string, extension: string, iconClass: ?string, iconSvgInline: ?string, mimetypes: list<string>, ratio: ?float, actionLabel: string}
	 */
	private function describe(TemplateFileCreator $creator): array {
		return $creator->jsonSerialize();
	}
}
