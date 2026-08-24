<?php

declare(strict_types=1);

namespace OCA\Office\Listener;

use OCA\Office\AppInfo\Application;
use OCA\Office\Service\CreatorCategoryService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;

/**
 * Adds one app menu action per registered template creator, linking to that
 * creator's category in the office app.
 *
 * @template-implements IEventListener<LoadAdditionalEntriesEvent>
 */
final class AppMenuActionListener implements IEventListener {
	/** @psalm-suppress PossiblyUnusedMethod Constructed by the DI container */
	public function __construct(
		private CreatorCategoryService $categoryService,
		private INavigationManager $navigationManager,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalEntriesEvent) {
			return;
		}

		// Creators are registered per user, so there is nothing to offer a guest.
		if (!$this->userSession->isLoggedIn()) {
			return;
		}

		$seen = [];
		foreach ($this->categoryService->listCreatorCategories() as $category) {
			$id = $category['id'];
			// Two suites can register a creator for the same category; the first
			// one wins, matching which one the category URL resolves to.
			if (isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;

			$entry = [
				'id' => Application::APP_ID . '-' . $id,
				'app' => Application::APP_ID,
				'type' => INavigationManager::TYPE_ACTION,
				'order' => $category['order'],
				'href' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.indexpath', ['path' => $id]),
				'name' => $category['label'],
				'icon' => $this->icon($category['iconSvgInline']),
			];

			// The indicator is only rendered when a color is set, and a category
			// outside the static map has none to give.
			if ($category['color'] !== null) {
				$entry['color'] = $category['color'];
			}

			$this->navigationManager->add($entry);
		}
	}

	/**
	 * Creators ship their icon as inline SVG, which the app menu cannot use: it
	 * paints the icon as a CSS background, so it needs a URL. A data URI keeps
	 * the creator's own icon without a route to serve it, and cannot execute the
	 * script an SVG may carry.
	 */
	private function icon(?string $svg): string {
		if ($svg === null || $svg === '') {
			return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
		}
		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}
}
