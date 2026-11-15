<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\Controller;

use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Service\JwkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class ApiController extends Controller {

	public function __construct(
		IRequest $request,
		private JwkService $jwkService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoCSRFRequired]
	#[PublicPage]
	public function getJwks(): JSONResponse {
		try {
			$jwks = $this->jwkService->getJwks();
			return new JSONResponse(['keys' => $jwks]);
		} catch (\Exception|\Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
