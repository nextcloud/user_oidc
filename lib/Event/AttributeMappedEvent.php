<?php
/*
 * @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
 *
 * @author Julius Härtl <jus@bitgrid.net>
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

use OCP\EventDispatcher\Event;

/**
 * Event to provide custom mapping logic based on the OIDC token data
 * In order to avoid further processing the event propagation should be stopped
 * in the listener after processing as the value might get overwritten afterwards
 * by other listeners through $event->stopPropagation();
 */
class AttributeMappedEvent extends Event {

	/** @var string */
	private $attribute;
	/** @var string */
	private $value;

	public function __construct(string $attribute, string $value) {
		parent::__construct();
		$this->attribute = $attribute;
		$this->value = $value;
	}

	/**
	 * @return string One of the ProviderService::SETTING_MAPPING_* constants for the attribute mapping that is currently processed
	 */
	public function getAttribute(): string {
		return $this->attribute;
	}

	public function getValue(): string {
		return $this->value;
	}

	public function setValue(string $value): void {
		$this->value = $value;
	}
}
