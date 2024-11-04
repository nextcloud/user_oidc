<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// IMapperException didn't extend Throwable until 27
// so we need this to test against stable25 and stable26
namespace OCP\AppFramework\Db {
	interface IMapperException extends \Throwable {
	}
}
