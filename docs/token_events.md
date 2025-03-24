<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Token events

Other apps can ask user_oidc for the login token (the one obtained when logging in Nextcloud using an external Oidc provider)
or a token from the "oidc" app (https://apps.nextcloud.com/apps/oidc). Those tokens might be useful for integration apps
that need to authenticate against another service's API.

## Get a token from an external provider

This is possible to get the current user's login token by emitting an event which will be received by user_oidc.
This is also possible to ask user_oidc to perform a token exchange, see token_exchange.md.
In this paragraph, we only talk about getting the login token (or a refreshed one), meaning it is delivered for the Oidc
client that was used when the user logged in Nextcloud.

To get the token obtained on login, user_oidc needs to store it and refresh it when needed. This is disabled by default.
You can enable this with:
``` bash
sudo -u www-data php /var/www/nextcloud/occ config:app:set --value=1 user_oidc store_login_token
```
This login token is refreshed by user_oidc when needed. So the token you will get by emitting the event will be valid (not expired).

Any Nextcloud app can emit the `ExternalTokenRequestedEvent` event:
```php
if (class_exists(OCA\UserOIDC\Event\ExternalTokenRequestedEvent::class)) {
	$event = new OCA\UserOIDC\Event\ExternalTokenRequestedEvent();
	try {
		$this->eventDispatcher->dispatchTyped($event);
	} catch (OCA\UserOIDC\Exception\GetExternalTokenFailedException $e) {
		$this->logger->debug('Failed to get external token: ' . $e->getMessage());
		$error = $e->getError();
		$errorDescription = $e->getErrorDescription();
		if ($error && $errorDescription) {
			$this->logger->debug('Token exchange error response from the IdP: ' . $error . ' (' . $errorDescription . ')');
		}
	}
	$token = $event->getToken();
	if ($token === null) {
		$this->logger->debug('There was no token found in the session');
	} else {
		$this->logger->debug('Obtained a token that expires in ' . $token->getExpiresInFromNow());
		// use the token
		$accessToken = $token->getAccessToken();
		$idToken = $token->getIdToken();
	}
} else {
	$this->logger->debug('The user_oidc app is not installed/available');
}
```

## Get an internal token

To get a token from the internal Oidc provider ("oidc" app), the `InternalTokenRequestedEvent` can be emitted.
No need to enable `store_login_token`.

```php
if (class_exists(OCA\UserOIDC\Event\InternalTokenRequestedEvent::class)) {
	$event = new OCA\UserOIDC\Event\InternalTokenRequestedEvent('my_target_audience');
    $this->eventDispatcher->dispatchTyped($event);
	$token = $event->getToken();
	if ($token === null) {
		$this->logger->debug('InternalTokenRequestedEvent, no token has been obtained from the oidc app');
	} else {
		$this->logger->debug('Obtained a token that expires in ' . $token->getExpiresInFromNow());
		// use the token
		$accessToken = $token->getAccessToken();
		$idToken = $token->getIdToken();
	}
} else {
	$this->logger->debug('The user_oidc app is not installed/available');
}
```
