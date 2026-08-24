<?php

declare(strict_types=1);

namespace OCA\Office\Service;

use OCA\Office\AppInfo\Application;
use OCP\Files\Template\ITemplateManager;
use OCP\Files\Template\TemplateFileCreator;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Maps the registered template creators onto the categories the office app
 * presents them as.
 *
 * A category id is the creator's identity in URLs (/apps/office/<id>) and in the
 * app menu, so it stays stable when an admin switches doc_format, which changes
 * a creator's extension and mimetypes.
 *
 * @psalm-type OfficeCategory = array{app: string, extension: string, id: string, label: string, mimetypes: list<string>}
 * @psalm-type OfficeCreatorCategory = array{app: string, extension: string, id: string, label: string, mimetypes: list<string>, color: ?string, order: int, iconSvgInline: ?string}
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

	/**
	 * Registering the creators runs every app that offers one, on every page
	 * load the app menu is built for. What comes out of it only changes when an
	 * app is installed, enabled or reconfigured, so it is worth a cache — bound
	 * by a TTL rather than invalidated, as none of those are observable here.
	 */
	private const int CACHE_TTL = 3600;

	private ICache $cache;

	/**
	 * A page of this app builds both the app menu and its own initial state, so
	 * the cache would be asked twice in one request.
	 *
	 * @var ?list<OfficeCreatorCategory>
	 */
	private ?array $creatorCategories = null;

	/** @psalm-suppress PossiblyUnusedMethod Constructed by the DI container */
	public function __construct(
		private IL10N $l10n,
		private ITemplateManager $templateManager,
		private IUserSession $userSession,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID . '-creator-categories');
	}

	/**
	 * Every registered creator with the category it belongs to. The frontend
	 * joins these onto the creators it gets from the templates API by app and
	 * extension.
	 *
	 * @return list<OfficeCategory>
	 */
	public function listCategories(): array {
		return array_map(
			static fn (array $category): array => [
				'app' => $category['app'],
				'extension' => $category['extension'],
				'id' => $category['id'],
				'label' => $category['label'],
				'mimetypes' => $category['mimetypes'],
			],
			$this->listCreatorCategories(),
		);
	}

	/**
	 * Every registered creator with everything the app menu presents it by.
	 * Nothing in here is request-scoped — no URLs, no theming — because it is
	 * served from the cache across requests.
	 *
	 * @return list<OfficeCreatorCategory>
	 */
	public function listCreatorCategories(): array {
		if ($this->creatorCategories !== null) {
			return $this->creatorCategories;
		}

		$key = $this->cacheKey();
		if ($key !== null) {
			/** @var ?list<OfficeCreatorCategory> $cached Written by this class only */
			$cached = $this->cache->get($key);
			if ($cached !== null) {
				return $this->creatorCategories = $cached;
			}
		}

		$categories = $this->describeCreators();
		if ($key !== null) {
			$this->cache->set($key, $categories, self::CACHE_TTL);
		}
		return $this->creatorCategories = $categories;
	}

	/**
	 * Creators are registered per user, and their labels are translated into the
	 * user's language: both belong in the key. A request without a user has
	 * nothing stable to key on, so its result is not cached.
	 */
	private function cacheKey(): ?string {
		$uid = $this->userSession->getUser()?->getUID();
		return $uid === null ? null : $uid . '-' . $this->l10n->getLanguageCode();
	}

	/**
	 * @return list<OfficeCreatorCategory>
	 */
	private function describeCreators(): array {
		$categories = [];
		foreach ($this->listCreators() as $creator) {
			$description = $this->describe($creator);
			$categories[] = [
				'app' => $creator->getAppId(),
				'extension' => $description['extension'],
				'id' => $this->categoryId($creator),
				'label' => $this->categoryLabel($creator),
				'mimetypes' => $this->categoryMimetypes($creator),
				'color' => $this->categoryColor($creator),
				'order' => $creator->getOrder(),
				'iconSvgInline' => $description['iconSvgInline'],
			];
		}
		return $categories;
	}

	/**
	 * @return list<TemplateFileCreator> Registered creators, ordered by the order they declared
	 */
	private function listCreators(): array {
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
