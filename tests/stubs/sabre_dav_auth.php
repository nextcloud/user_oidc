<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Sabre\HTTP {

	interface RequestInterface {
	}

	interface ResponseInterface {
	}
}

namespace Sabre\DAV {

	class Server {
		public function getPlugin(string $name) {
			return null;
		}
	}
}

namespace Sabre\DAV\Auth {

	class Plugin {
		public function addBackend($backend): void {
		}
	}
}

namespace Sabre\DAV\Auth\Backend {

	abstract class AbstractBearer {
	}
}
