<?php

declare(strict_types=1);

namespace OCA\Office\Controller;

use OCA\Office\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		// editor-url is not provided here — OfficeOverview.vue calls
		// loadState('office', 'editor-url', null) and falls back to /f/{fileid}
		// when the state is absent. A WOPI backend branch injects a real URL.
		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}
}
