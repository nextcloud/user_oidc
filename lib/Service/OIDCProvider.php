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

namespace OCA\UserOIDC\Service;

class OIDCProvider {

	public function getId(): int {
		return 0;
	}

	public function getAuthEndpoint(): string {
		return 'https://accounts.google.com/o/oauth2/v2/auth';
	}

	public function getTokenEndpoint(): string {
		return 'https://oauth2.googleapis.com/token';
	}

	public function getClientId(): string {
		return '13912616499-h67ma3s9p4h81rt5ihk5e4h9lcbkelbn.apps.googleusercontent.com';
	}

	public function getClientSecret(): string {
		return 'lDYiIuJy6KzWTKVApv8KWp7s';
	}

	public function getRedirectURL(): string {
		return 'https://localhost/index.php/apps/user_oidc/code';
	}

	public function getScope(): string {
		return 'email openid profile';
	}
}
