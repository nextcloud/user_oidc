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

		['name' => 'Timezone#setTimezone', 'url' => '/config/timezone', 'verb' => 'POST'],
	],
	'ocs' => [
		['name' => 'Settings#getProviders', 'url' => '/api/{apiVersion}/provider', 'verb' => 'GET', 'requirements' => $requirements],
		['name' => 'Settings#createProvider', 'url' => '/api/{apiVersion}/provider', 'verb' => 'POST', 'requirements' => $requirements],
		['name' => 'Settings#updateProvider', 'url' => '/api/{apiVersion}/provider/{providerId}', 'verb' => 'PUT', 'requirements' => $requirements],
		['name' => 'Settings#deleteProvider', 'url' => '/api/{apiVersion}/provider/{providerId}', 'verb' => 'DELETE', 'requirements' => $requirements],
		['name' => 'Settings#setID4ME', 'url' => '/api/{apiVersion}/provider/id4me', 'verb' => 'POST', 'requirements' => $requirements],
		['name' => 'Settings#getSupportedSettings', 'url' => '/api/{apiVersion}/supported-settings', 'verb' => 'GET', 'requirements' => $requirements],
		['name' => 'Settings#setAdminConfig', 'url' => '/api/{apiVersion}/admin-config', 'verb' => 'POST', 'requirements' => $requirements],

		['name' => 'ocsApi#createUser', 'url' => '/api/{apiVersion}/user', 'verb' => 'POST', 'requirements' => $requirements],
		['name' => 'ocsApi#deleteUser', 'url' => '/api/{apiVersion}/user/{userId}', 'verb' => 'DELETE', 'requirements' => $requirements],
	],
];
