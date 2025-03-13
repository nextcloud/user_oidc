<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
### Token exchange

If your IdP supports token exchange, user_oidc can exchange the login token against another token.

:warning: The token exchange feature requires to store the login token which is disabled by default. You can enable it with:
``` bash
sudo -u www-data php /var/www/nextcloud/occ config:app:set --value=1 user_oidc store_login_token
```

Keycloak supports token exchange if its "Preview" mode is enabled. See https://www.keycloak.org/securing-apps/token-exchange .

:warning: Your IdP need to be configured accordingly. For example, Keycloak requires that token exchange is explicitely
authorized for the target Oidc client.

The type of token exchange that user_oidc can perform is "Internal token to internal token"
(https://www.keycloak.org/securing-apps/token-exchange#_internal-token-to-internal-token-exchange).
This means you can exchange a token delivered for an audience "A" for a token delivered for an audience "B".
In other words, you can get a token of a different Oidc client than the one you configured in user_oidc.

In short, you don't need the client ID and client secret of the target audience's client.
Providing a token for the audience "A" (the login token) is enough to obtain a token for the audience "B".

user_oidc is storing the login token in the user's Nextcloud session and takes care of refreshing it when needed.
When another app wants to exchange the current login token for another one,
it can dispatch the `OCA\UserOIDC\Event\ExchangedTokenRequestedEvent` event.
The exchanged token is immediately stored in the event object itself.

```php
if (class_exists(OCA\UserOIDC\Event\ExchangedTokenRequestedEvent:class)) {
	$event = new OCA\UserOIDC\Event\ExchangedTokenRequestedEvent('my_target_audience');
	try {
		$this->eventDispatcher->dispatchTyped($event);
	} catch (OCA\UserOIDC\Exception\TokenExchangeFailedException $e) {
		$this->logger->debug('Failed to exchange token: ' . $e->getMessage());
		$error = $e->getError();
		$errorDescription = $e->getErrorDescription();
		if ($error && $errorDescription) {
			$this->logger->debug('Token exchange error response from the IdP: ' . $error . ' (' . $errorDescription . ')');
		}
	}
	$token = $event->getToken();
	if ($token === null) {
		$this->logger->debug('ExchangedTokenRequestedEvent event has not been caught by user_oidc');
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
