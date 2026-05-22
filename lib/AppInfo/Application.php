<?php

declare(strict_types=1);

namespace OCA\Office\AppInfo;

use OCA\Office\BackgroundJob\CleanupJob;
use OCA\Office\Settings\Admin;
use OCA\Office\TokenManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'office';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerSettings(Admin::class);
		$context->registerJob(CleanupJob::class);

		$context->registerService(TokenManager::class, static function ($c) {
			return new TokenManager(
				$c->get(IRootFolder::class),
				$c->get(\OCA\Office\Db\WopiMapper::class),
				$c->get(IURLGenerator::class),
				$c->get(IEventDispatcher::class),
				$c->get(LoggerInterface::class),
				$c->get(IUserSession::class)->getUser()?->getUID(),
			);
		});
	}

	public function boot(IBootContext $context): void {
	}
}
