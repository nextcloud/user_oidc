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
 * so changing one is a breaking change for them. Constant names may be renamed.
 */
final class Operation {
	public const PROVIDER_CREATED = 'user_oidc.provider.created';
	public const PROVIDER_UPDATED = 'user_oidc.provider.updated';
	public const PROVIDER_DELETED = 'user_oidc.provider.deleted';
	public const ID4ME_UPDATED = 'user_oidc.id4me.updated';
	public const ADMIN_CONFIG_UPDATED = 'user_oidc.admin_config.updated';
	public const USER_PROVISIONED = 'user_oidc.user.provisioned';
	public const LOGIN_FAILED = 'user_oidc.login.failed';
	public const SESSION_REVOKED = 'user_oidc.session.revoked';

	/**
	 * Short description of when each operation is logged, used for the documentation
	 */
	public const DESCRIPTIONS = [
		self::PROVIDER_CREATED => 'An OpenID Connect provider was created',
		self::PROVIDER_UPDATED => 'The configuration of an OpenID Connect provider was changed',
		self::PROVIDER_DELETED => 'An OpenID Connect provider was deleted',
		self::ID4ME_UPDATED => 'An admin enabled or disabled the login with ID4me',
		self::ADMIN_CONFIG_UPDATED => 'An admin enabled or disabled storing the tokens users obtain from the identity provider at login',
		self::USER_PROVISIONED => 'An account was created for a user of an identity provider through the provisioning API, a bearer token or an ID4me login',
		self::LOGIN_FAILED => 'A login through an OpenID Connect provider was rejected, for example because of an invalid state, token or nonce or a group restriction',
		self::SESSION_REVOKED => 'An identity provider ended a session of a user through a back-channel logout',
	];

	private function __construct() {
	}
}
