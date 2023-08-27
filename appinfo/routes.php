<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

return [
	'routes' => [
		['name' => 'login#login', 'url' => '/login/{providerId}', 'verb' => 'GET'],
		['name' => 'login#code', 'url' => '/code', 'verb' => 'GET'],
		['name' => 'login#singleLogoutService', 'url' => '/sls', 'verb' => 'GET'],
		['name' => 'login#backChannelLogout', 'url' => '/backchannel-logout/{providerIdentifier}', 'verb' => 'POST'],

        // this is a security problem combined with Telekom provisioning, so we habe to disable the endpoint
		// ['name' => 'api#createUser', 'url' => '/user', 'verb' => 'POST'],

		['name' => 'id4me#showLogin', 'url' => '/id4me', 'verb' => 'GET'],
		['name' => 'id4me#login', 'url' => '/id4me', 'verb' => 'POST'],
		['name' => 'id4me#code', 'url' => '/id4me/code', 'verb' => 'GET'],

		['name' => 'Settings#createProvider', 'url' => '/provider', 'verb' => 'POST'],
		['name' => 'Settings#updateProvider', 'url' => '/provider/{providerId}', 'verb' => 'PUT'],
		['name' => 'Settings#deleteProvider', 'url' => '/provider/{providerId}', 'verb' => 'DELETE'],
		['name' => 'Settings#setID4ME', 'url' => '/provider/id4me', 'verb' => 'POST'],

		['name' => 'Timezone#setTimezone', 'url' => '/config/timezone', 'verb' => 'POST'],
	]
];
