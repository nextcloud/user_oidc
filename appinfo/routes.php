<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

$requirements = [
	'apiVersion' => '(v1)',
];

return [
	'routes' => [
		['name' => 'login#login', 'url' => '/login/{providerId}', 'verb' => 'GET'],
		['name' => 'login#code', 'url' => '/code', 'verb' => 'GET'],
		['name' => 'login#singleLogoutService', 'url' => '/sls', 'verb' => 'GET'],
		['name' => 'login#backChannelLogout', 'url' => '/backchannel-logout/{providerIdentifier}', 'verb' => 'POST'],

		['name' => 'api#createUser', 'url' => '/user', 'verb' => 'POST'],
		['name' => 'api#deleteUser', 'url' => '/user/{userId}', 'verb' => 'DELETE'],

		['name' => 'id4me#showLogin', 'url' => '/id4me', 'verb' => 'GET'],
		['name' => 'id4me#login', 'url' => '/id4me', 'verb' => 'POST'],
		['name' => 'id4me#code', 'url' => '/id4me/code', 'verb' => 'GET'],

		['name' => 'Settings#createProvider', 'url' => '/provider', 'verb' => 'POST'],
		['name' => 'Settings#updateProvider', 'url' => '/provider/{providerId}', 'verb' => 'PUT'],
		['name' => 'Settings#deleteProvider', 'url' => '/provider/{providerId}', 'verb' => 'DELETE'],
		['name' => 'Settings#setID4ME', 'url' => '/provider/id4me', 'verb' => 'POST'],
		['name' => 'Settings#setAdminConfig', 'url' => '/admin-config', 'verb' => 'POST'],

		['name' => 'Timezone#setTimezone', 'url' => '/config/timezone', 'verb' => 'POST'],
	],
	'ocs' => [
		['name' => 'ocsApi#createUser', 'url' => '/api/{apiVersion}/user', 'verb' => 'POST', 'requirements' => $requirements],
		['name' => 'ocsApi#deleteUser', 'url' => '/api/{apiVersion}/user/{userId}', 'verb' => 'DELETE', 'requirements' => $requirements],
	],
];
