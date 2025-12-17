<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# user_oidc

[![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/user_oidc)](https://api.reuse.software/info/github.com/nextcloud/user_oidc)

OpenID Connect user backend for Nextcloud

## General usage
See [Nextcloud and OpenID-Connect](https://web.archive.org/web/20240412121655/https://www.schiessle.org/articles/2023/07/04/nextcloud-and-openid-connect/)
for a proper jumpstart.

---

## `user_oidc.httpclient.allowselfsigned`

```php
'user_oidc' => [
    'httpclient.allowselfsigned' => true,
]
```

This configuration allows Nextcloud to **trust self-signed SSL certificates** when making HTTP requests through the internal HTTP client.
It is especially useful when your OAuth2 or OIDC provider is hosted locally or uses a self-signed certificate not recognized by public CAs.

* **true**: Disables SSL certificate verification (adds the `verify => false` option to the actual HTTP client)
* **false** (default): SSL verification remains enabled and strict

> ⚠️ Use with caution in production environments, as disabling certificate verification can introduce security risks.

---

## `user_oidc.prompt`

```php
'user_oidc' => [
  'prompt' => 'internal'
]
```

This option allows customizing the `prompt` parameter sent in the OAuth2/OIDC authorization request.

Supported values include:

* `none`
* `login`
* `consent`
* `internal` (custom)

The `internal` prompt is specific to **[OAuth2 Passport Server](https://github.com/elyerr/oauth2-passport-server)** and is designed to enable seamless login
for private or internal applications without requiring user consent or interaction.

Documentation for all supported prompt values is available here:
[Oauth2 passport server prompts-supported](https://gitlab.com/elyerr/oauth2-passport-server/-/wikis/home/prompts-supported)

## `user_oidc.default_token_endpoint_auth_method`

The OIDC specifications are clear on this. It is stated in https://openid.net/specs/openid-connect-discovery-1_0.html
that if `token_endpoint_auth_methods_supported` is not set in the provider discovery endpoint payload,
`client_secret_basic` should be used as default authentication method.

But it has been reported that, with Authelia for example, only `client_secret_post` might be allowed while `token_endpoint_auth_methods_supported`
is not set in the discovery. In such case, you can set the default token endpoint authentication method with:

```php
'user_oidc' => [
  'default_token_endpoint_auth_method' => 'client_secret_post'
]
```

## `user_oidc.validate_jwk_strength`

By default, user_oidc validates the strength of the JWK keys received from the discovery endpoint.
It will check that RSA keys are long enough and that EC/OKP keys have the correct curve.
This can be disabled with:

```php
'user_oidc' => [
  'validate_jwk_strength' => false
]
```

---

### User IDs

The OpenID Connect backend will ensure that user ids are unique even when multiple providers would report the same user
id to ensure that a user cannot identify for the same Nextcloud account through different providers.
Therefore, a hash of the provider id and the user id is used. This behaviour can be turned off in the provider options.

## Commandline settings
The app could also be configured by commandline.

### Provider entries
Providers are located by provider identifier.

To list all configured providers, use:
```
sudo -u www-data php /var/www/nextcloud/occ user_oidc:provider
```

To show detailed provider configuration, use:
```
sudo -u www-data php /var/www/nextcloud/occ user_oidc:provider demoprovider
```

A provider is created if none with the given identifier exists and all parameters are given:
```
sudo -u www-data php /var/www/nextcloud/occ user_oidc:provider demoprovider --clientid="WBXCa003871" \
    --clientsecret="lbXy***********" --discoveryuri="https://accounts.example.com/openid-configuration"
```

Other options like attribute mappings or group provisioning can be optionally specified. For more details refer to `occ user_oidc:provider --help`.

To delete a provider, use:
```
sudo -u www-data php /var/www/nextcloud/occ user_oidc:provider:delete demoprovider
  Are you sure you want to delete OpenID Provider demoprovider
  and may invalidate all assiciated user accounts.
```
To skip the confirmation, use `--force`.

***Warning***: be careful with the deletion of a provider because in some setup, this invalidates access to all
NextCloud accounts associated with this provider.

#### JWKS cache invalidation

A provider-specific JWKS cache is stored by user_oidc. This cache is valid for one hour.
If the JWKS changed on the IdP side, you can clear this JWKS
cache by editing the provider with occ. You don't have to change any value. For example, if your provider identifier is
`my_identifier` and the client ID is `my_client_id`, you can run:

```
occ user_oidc:provider my_identifier --clientid my_client_id
```

to clear the JWKS cache of the provider `my_identifier`.

#### Avatar support

The avatar attribute on your IdP side may contain a URL pointing to an image file or directly a base64 encoded image.
The base64 should start with `data:image/png;base64,` or `data:image/jpeg;base64,`.
The image should be in JPG or PNG format and have the same width and height.

### Custom login button label

You can set a custom label for the buttons in the login page.

Set this value in `config.php`:
``` php
'user_oidc' => [
    'login_label' => 'Connect with {name}',
],
```
This custom label won't be translated.

### Disable default claims

Even if you don't map any attribute for quota, display name, email or groups, this application will
ask for the 'quota', 'name', 'email', 'groups' claims and map them to an attribute with the same name.

To change this behaviour and disable the default claims, you can change this value in `config.php`:
``` php
'user_oidc' => [
    'enable_default_claims' => false,
],
```

When default claims are disabled, each claim will be asked for only if there is an attribute explicitely mapped
in the OpenId client settings (in Nextcloud's admin settings).

### Call the userinfo endpoint to enrich the login ID token

If some user information is not in your login ID tokens but can be obtained with the userinfo endpoint, just enable
`enrich_login_id_token_with_userinfo` in config.php. This is disabled by default.
``` php
'user_oidc' => [
    'enrich_login_id_token_with_userinfo' => true,
],
```

This will use the content of the userinfo endpoint response just like if it had been included in the login ID token.

This will only work on login and not when validating a bearer token
because provisioning when validating a bearer access token is not supported yet.

### ID4me option
ID4me is an application setting switch which is configurable as normal Nextcloud app setting:
```
sudo -u www-data php /var/www/nextcloud/occ config:app:set --value=1 user_oidc id4me_enabled
```

### Disable other login methods
If there is only one OpenID Connect provider configured, it can be made the default login
method and the user would get redirected to the provider immediately for the
login. Admins can still use the regular login through adding the `?direct=1`
parameter to the login URL.

```bash
sudo -u www-data php var/www/nextcloud/occ config:app:set --type=string --value=0 user_oidc allow_multiple_user_backends
```

### PKCE

This app supports PKCE (Proof Key for Code Exchange).
https://datatracker.ietf.org/doc/html/rfc7636
Unless PKCE is not supported by the configured OpenID Connect provider,
it is enabled by default.
You can also manually disable it in `config.php`:
``` php
'user_oidc' => [
    'use_pkce' => false,
],
```

### Single logout

Single logout is enabled by default. When logging out of Nextcloud,
the end_session_endpoint of the OpenID Connect provider is requested to end the session on this side.

It can be disabled in `config.php`:
``` php
'user_oidc' => [
    'single_logout' => false,
],
```

### Backchannel logout

[OpenId backchannel logout](https://openid.net/specs/openid-connect-backchannel-1_0.html) is supported by this app.
You just have to configure 2 settings for the OpenId client (on the provider side, Keycloak for example):
1. Backchannel Logout URL: If your Nextcloud base URL is https://my.nextcloud.org
and your OpenId provider identifier (on the Nextcloud side) is "myOidcProvider"
set the backchannel Logout URL to
https://my.nextcloud.org/index.php/apps/user_oidc/backchannel-logout/myOidcProvider .
This URL is provided for each provider in the OpenID Connect admin settings.
2. Enable the "Backchannel Logout Session Required" setting.

### Auto provisioning

By default, this app provisions the users with the information contained in the OIDC token
which means it gets the user information (such as the display name or the email) from the ID provider.
This also means that user_oidc takes care of creating the users when they first log in.

It is possible to disable auto provisioning to let other user backends (like LDAP)
take care of user creation and attribute mapping.
This leaves user_oidc to only take care of authentication.

Auto provisioning can be disabled in `config.php`:
``` php
'user_oidc' => [
    'auto_provision' => false,
],
```

:warning: When relying on the LDAP user backend for user provisioning, you need to adjust the
"Login Attributes" section and the Expert tab's "Internal Username" value of your LDAP settings.
Even if LDAP does not handle the login process,
the user_oidc app will trigger an LDAP search when logging in to make sure the user is created if it was
not synced already.
So it is essential that:
* the OpenID Connect "User ID mapping" attribute matches the LDAP Expert tab's "Internal Username".
The attribute names can be different but their values should match. Do not change the LDAP configuration,
simply adapt the OpenID Connect provider configuration.
* the OpenID Connect "User ID mapping" attribute can be used in the LDAP login query
defined in the "Login Attributes" tab.

In other words, make sure that your OpenID Connect provider's "User ID mapping" setting is set to an attribute
which provides the same values as the LDAP attribute set in "Internal Username" in your LDAP settings.

#### Soft auto provisioning

If you have existing users managed by another backend (local or LDAP users for example) and you want them to be managed
by user_oidc but you still want user_oidc to auto-provision users
(create new users when they are in the Oidc IdP but not found in any other user backend),
this is possible with **soft** auto provisioning.

There is a `soft_auto_provision` system config flag that is enabled by default and is effective only if `auto_provision`
is enabled.
``` php
'user_oidc' => [
    'auto_provision' => true, // default: true
    'soft_auto_provision' => true, // default: true
],
```

* When `soft_auto_provision` is enabled
  * If the user already exists in another backend, we don't create a new one in the user_oidc backend.
    We update the information (mapped attributes) of the existing user.
    If the user does not exist in another backend, we create it in the user_oidc backend
* When `soft_auto_provision` is disabled
  * We refuse Oidc login of users that already exist in other backends

#### Soft auto provisioning without user creation

You might want soft auto provisioning but prevent user_oidc to create users,
meaning you want user_oidc to accept connection only for users that already exist in Nextcloud and are managed by other
user backend BUT you still want user_oidc to set the user information according to the OIDC mapped attributes.

For that, there is a `disable_account_creation` system config flag that is false by default and is effective
only if `auto_provision` and `soft_auto_provision` are enabled
is enabled.
``` php
'user_oidc' => [
    'auto_provision' => true, // default: true
    'soft_auto_provision' => true, // default: true
    'disable_account_creation' => true, // default: false
],
```

### 4 Provisioning scenarios

* Create users if they don't exist
    * Accept connection of existing users (from other backends) and update their attributes:
      ``` php
      'user_oidc' => [
          'auto_provision' => true, // default: true
          'soft_auto_provision' => true, // default: true
      ],
      ```
    * Do not accept connection of users existing in other backends:
	  ``` php
	  'user_oidc' => [
		  'auto_provision' => true, // default: true
		  'soft_auto_provision' => false, // default: true
	  ],
	  ```
* Do not create users if they don't exist
    * Update attributes of existing users (created by user_oidc or any other backend):
	  ``` php
	  'user_oidc' => [
	  	'auto_provision' => true, // default: true
	  	'soft_auto_provision' => true, // default: true
	  	'disable_account_creation' => true, // default: false
	  ],
	  ```
    * Do not update attributes of existing users:
      ``` php
      'user_oidc' => [
          'auto_provision' => false, // default: true
      ],
      ```

### Pre-provisioning

If you need the users to exist before they authenticate for the first time
(because you want other users to be able to share files with them, for example)
you can pre-provision them with the user_oidc API:

``` bash
curl -H "ocs-apirequest: true" -u admin:admin -X POST -H "content-type: application/json" \
  -d '{"providerId":2,"userId":"new_user","displayName":"New User","email":"new@user.org","quota":"5GB"}' \
  https://my.nextcloud.org/ocs/v2.php/apps/user_oidc/api/v1/user
```

Only the `providerId` and `userId` parameters are mandatory.

You can also delete users managed by user_oidc with this API endpoint:

``` bash
curl -H "ocs-apirequest: true" -u admin:admin -X DELETE
  https://my.nextcloud.org/ocs/v2.php/apps/user_oidc/api/v1/user/USER_ID
```

### Bearer token validation

The OIDC tokens used to make API call to Nextcloud might have been generated by an external entity.
It is possible that they don't contain the user ID attribute. In this case, this attribute
can be requested to the provider's `userinfo` endpoint.

Add this to `config.php` to enable such extra validation step:
``` php
'user_oidc' => [
    'userinfo_bearer_validation' => true,
],
```

If you only want the token to be validated against the `userinfo` endpoint,
it is possible to disable the classic "self-encoded" validation:
``` php
'user_oidc' => [
    'userinfo_bearer_validation' => true,
    'selfencoded_bearer_validation' => false,
],
```

If you want to ask the [OIDC Identity Provider app](https://apps.nextcloud.com/apps/oidc) to validate a bearer token:
``` php
'user_oidc' => [
    'oidc_provider_bearer_validation' => true,
],
```
This requires the OIDC Identity Provider app >= v1.4.0 . Access tokens and JWT tokens can be validated.

### Group provisioning

You can configure each provider:
* Toggle group provisioning (creates nonexisting groups on login)
* Set the group whitelist regular expression (nonmatching groups will be kept untouched)
* Toggle login restriction to people who don't belong to any whitelisted group

This can be done in the graphical settings, in the "OpenID Connect" admin settings section or with the occ command to create/update providers:

```
sudo -u www-data php /var/www/nextcloud/occ user_oidc:provider demoprovider \
                --clientid="..." --clientsecret="***" --discoveryuri="..." \
                --group-provisioning=1 --group-whitelist-regex='/<regex>/' --group-restrict-login-to-whitelist=1
```

### Disable audience and azp checks

The `audience` and `azp` token claims will be checked when validating a login ID token.
Only the `audience` will be checked when validating a Bearer token.
You can disable these checks with these config values (in config.php):
``` php
'user_oidc' => [
    'login_validation_audience_check' => false,
    'login_validation_azp_check' => false,
    'selfencoded_bearer_validation_audience_check' => false,
],
```

### Disable the user search by email

This app can stop matching users (when a user search is performed in Nextcloud) by setting this config.php value:
``` php
'user_oidc' => [
    'user_search_match_emails' => false,
],
```

### Optional: Enable support for nested and fallback claim mappings

By default, claim mapping in this app uses **flat attribute keys** like `email`, `name`, `custom.nickname`, etc.
However, some Identity Providers return **structured tokens** (nested JSON), and mapping such claims requires dot-notation (e.g. `custom.nickname` → `{ "custom": { "nickname": "value" } }`).

Additionally, you may want to define **fallbacks**, in case a preferred claim is missing, using the `|` separator.

#### Example

```
custom.nickname | profile.name | name
```

This will return the first non-empty string from the token in the order defined.


#### Enabling this behavior (optional)

To enable support for dot-notation and fallback claims for a specific provider, set the following configuration flag via the Nextcloud command line:

```bash
php occ user_oidc:provider <your-provider-identifier> --resolve-nested-claims=1
```

To disable again:

```bash
php occ user_oidc:provider <your-provider-identifier> --resolve-nested-claims=0
```

This setting is also available in the web interface when configuring a provider.
This setting is **disabled by default** to ensure full backward compatibility with existing configurations and flat token structures.


## Building the app

Requirements for building:
- Node.js 14
- NPM 7
- PHP
- composer

The app uses [krankerl](https://github.com/ChristophWurst/krankerl) to build the release archive from the git repository.
The release will be put into `build/artifacts/` when running the `krankerl package`.

The app can also be built without krankerl by manually running:
```
composer install --no-dev -o
npm ci
npm run build
```

On Ubuntu 20.04, a possible way to get build working is with matching npm and node versions is:
```
sudo apt-get remove nodejs
sudo curl -sL https://deb.nodesource.com/setup_14.x | sudo -E bash -
sudo apt-get install nodejs
sudo npm install -g npm@7
```


