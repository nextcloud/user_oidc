<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC\AuditLog;

/**
 * Operations OpenID Connect user backend writes to the admin audit log, in the form `app.entity.action`
 *
 * The values are matched by log consumers (filters, alert rules, SIEM imports),
 * so changing one is a breaking change for them. Case names may be renamed.
 */
enum Operation: string {
	case ProviderCreated = 'user_oidc.provider.created';
	case ProviderUpdated = 'user_oidc.provider.updated';
	case ProviderDeleted = 'user_oidc.provider.deleted';
	case Id4meUpdated = 'user_oidc.id4me.updated';
	case AdminConfigUpdated = 'user_oidc.admin_config.updated';

	/**
	 * Short description of when the operation is logged, used for the documentation
	 */
	public function description(): string {
		return match ($this) {
			self::ProviderCreated => 'An OpenID Connect provider was created',
			self::ProviderUpdated => 'The configuration of an OpenID Connect provider was changed',
			self::ProviderDeleted => 'An OpenID Connect provider was deleted',
			self::Id4meUpdated => 'An admin enabled or disabled the login with ID4me',
			self::AdminConfigUpdated => 'An admin enabled or disabled storing the tokens users obtain from the identity provider at login',
		};
	}
}
