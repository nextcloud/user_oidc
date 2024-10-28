<?php
/*
 * @copyright Copyright (c) 2022 Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace OCA\UserOIDC\Event;

use OCA\UserOIDC\Model\Token;
use OCP\EventDispatcher\Event;

/**
 * This event is emitted with by other apps which need an exchanged token for another audience (another client ID)
 */
class ExchangedTokenRequestedEvent extends Event {

	private ?Token $token = null;

	public function __construct(
		private string $targetAudience,
	) {
		parent::__construct();
	}

	public function getTargetAudience(): string {
		return $this->targetAudience;
	}

	public function setTargetAudience(string $targetAudience): void {
		$this->targetAudience = $targetAudience;
	}

	public function getToken(): ?Token {
		return $this->token;
	}

	public function setToken(?Token $token): void {
		$this->token = $token;
	}
}
