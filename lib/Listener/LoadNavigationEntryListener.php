<?php

declare(strict_types=1);

namespace OCA\Office\Listener;

use OCA\Office\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;

/**
 * Registers the app's main navigation entry. It is added here rather than in
 * info.xml so that the name stays the untranslated product name "Office".
 *
 * @template-implements IEventListener<LoadAdditionalEntriesEvent>
 */
final class LoadNavigationEntryListener implements IEventListener {
	/** @psalm-suppress PossiblyUnusedMethod Constructed by the DI container */
	public function __construct(
		private IAppManager $appManager,
		private INavigationManager $navigationManager,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalEntriesEvent) {
			return;
		}

		/** @psalm-suppress InvalidArgument OCP types the closure as non-nullable, but NavigationManager skips null entries */
		$this->navigationManager->add(function (): ?array {
			if (!$this->appManager->isEnabledForUser(Application::APP_ID)) {
				return null;
			}

			return [
				'id' => Application::APP_ID,
				'app' => Application::APP_ID,
				'type' => INavigationManager::TYPE_APPS,
				'order' => 10,
				'href' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index'),
				'icon' => $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
				'name' => 'Office',
			];
		});
	}
}
