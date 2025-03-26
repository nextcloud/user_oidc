OC.L10N.register(
    "user_oidc",
    {
    "Login with %1s" : "Login with %1s",
    "ID4Me is disabled" : "ID4Me is disabled",
    "Invalid OpenID domain" : "Invalid OpenID domain",
    "Invalid authority issuer" : "Invalid authority issuer",
    "Multiple authority found" : "Multiple authority found",
    "The received state does not match the expected value." : "The received state does not match the expected value.",
    "Authority not found" : "Authority not found",
    "Failed to decrypt the ID4ME provider client secret" : "Failed to decrypt the ID4ME provider client secret",
    "The received token is expired." : "The received token is expired.",
    "The audience does not match ours" : "The audience does not match ours",
    "The authorized party does not match ours" : "The authorised party does not match ours",
    "No authorized party" : "No authorised party",
    "The nonce does not match" : "The nonce does not match",
    "You must access Nextcloud with HTTPS to use OpenID Connect." : "You must access Nextcloud with HTTPS to use OpenID Connect.",
    "There is no such OpenID Connect provider." : "There is no such OpenID Connect provider.",
    "Could not reach the OpenID Connect provider." : "Could not reach the OpenID Connect provider.",
    "Failed to decrypt the OIDC provider client secret" : "Failed to decrypt the OIDC provider client secret",
    "Failed to contact the OIDC provider token endpoint" : "Failed to contact the OIDC provider token endpoint",
    "The issuer does not match the one from the discovery endpoint" : "The issuer does not match the one from the discovery endpoint",
    "Failed to provision the user" : "Failed to provision the user",
    "You do not have permission to log in to this instance. If you think this is an error, please contact an administrator." : "You do not have permission to log in to this instance. If you think this is an error, please contact an administrator.",
    "User conflict" : "User conflict",
    "OpenID Connect" : "OpenID Connect",
    "OpenID Connect user backend" : "OpenID Connect user backend",
    "Use an OpenID Connect backend to login to your Nextcloud" : "Use an OpenID Connect backend to login to your Nextcloud",
    "Allows flexible configuration of an OIDC server as Nextcloud login user backend." : "Allows flexible configuration of an OIDC server as Nextcloud login user backend.",
    "Could not save ID4me state: {msg}" : "Could not save ID4me state: {msg}",
    "Could not save storeLoginToken state: {msg}" : "Could not save storeLoginToken state: {msg}",
    "Could not update the provider:" : "Could not update the provider:",
    "Could not remove provider: {msg}" : "Could not remove provider: {msg}",
    "Could not register provider:" : "Could not register provider:",
    "Allows users to authenticate via OpenID Connect providers." : "Allows users to authenticate via OpenID Connect providers.",
    "Enable ID4me" : "Enable ID4me",
    "Store login tokens" : "Store login tokens",
    "This is needed if you are using other apps that want to use user_oidc's token exchange or simply get the login token" : "This is needed if you are using other apps that want to use user_oidc's token exchange or simply get the login token",
    "Registered Providers" : "Registered Providers",
    "Register new provider" : "Register new provider",
    "Register a new provider" : "Register a new provider",
    "Configure your provider to redirect back to {url}" : "Configure your provider to redirect back to {url}",
    "No providers registered." : "No providers registered.",
    "Client ID" : "Client ID",
    "Discovery endpoint" : "Discovery endpoint",
    "Backchannel Logout URL" : "Backchannel Logout URL",
    "Redirect URI (to be authorized in the provider client configuration)" : "Redirect URI (to be authorized in the provider client configuration)",
    "Update" : "Update",
    "Remove" : "Remove",
    "Update provider settings" : "Update provider settings",
    "Update provider" : "Update provider",
    "Submit" : "Submit",
    "Client configuration" : "Client configuration",
    "Identifier (max 128 characters)" : "Identifier (max 128 characters)",
    "Display name to identify the provider" : "Display name to identify the provider",
    "Client secret" : "Client secret",
    "Leave empty to keep existing" : "Leave empty to keep existing",
    "Warning, if the protocol of the URLs in the discovery content is HTTP, the ID token will be delivered through an insecure connection." : "Warning, if the protocol of the URLs in the discovery content is HTTP, the ID token will be delivered through an insecure connection.",
    "Custom end session endpoint" : "Custom end session endpoint",
    "Scope" : "Scope",
    "Extra claims" : "Extra claims",
    "Attribute mapping" : "Attribute mapping",
    "User ID mapping" : "User ID mapping",
    "Quota mapping" : "Quota mapping",
    "Groups mapping" : "Groups mapping",
    "Extra attributes mapping" : "Extra attributes mapping",
    "Display name mapping" : "Display name mapping",
    "Gender mapping" : "Gender mapping",
    "Email mapping" : "Email mapping",
    "Phone mapping" : "Phone mapping",
    "Language mapping" : "Language mapping",
    "Role/Title mapping" : "Role/Title mapping",
    "Street mapping" : "Street mapping",
    "Postal code mapping" : "Postal code mapping",
    "Locality mapping" : "Locality mapping",
    "Region mapping" : "Region mapping",
    "Country mapping" : "Country mapping",
    "Organisation mapping" : "Organisation mapping",
    "Website mapping" : "Website mapping",
    "Avatar mapping" : "Avatar mapping",
    "Biography mapping" : "Biography mapping",
    "X (formerly Twitter) mapping" : "X (formerly Twitter) mapping",
    "Fediverse/Nickname mapping" : "Fediverse/Nickname mapping",
    "Headline mapping" : "Headline mapping",
    "Authentication and Access Control Settings" : "Authentication and Access Control Settings",
    "Use unique user ID" : "Use unique user ID",
    "By default every user will get a unique user ID that is a hashed value of the provider and user ID. This can be turned off but uniqueness of users accross multiple user backends and providers is no longer preserved then." : "By default every user will get a unique user ID that is a hashed value of the provider and user ID. This can be turned off but uniqueness of users accross multiple user backends and providers is no longer preserved then.",
    "Use provider identifier as prefix for IDs" : "Use provider identifier as prefix for IDs",
    "To keep IDs in plain text, but also preserve uniqueness of them across multiple providers, a prefix with the providers name is added." : "To keep IDs in plain text, but also preserve uniqueness of them across multiple providers, a prefix with the providers name is added.",
    "Use group provisioning." : "Use group provisioning.",
    "This will create and update the users groups depending on the groups claim in the ID token. The Format of the groups claim value should be {sample1}, {sample2} or {sample3}" : "This will create and update the users groups depending on the groups claim in the ID token. The Format of the groups claim value should be {sample1}, {sample2} or {sample3}",
    "Group whitelist regex" : "Group whitelist regex",
    "Only groups matching the whitelist regex will be created, updated and deleted by the group claim. For example: {regex} allows all groups which ID starts with {substr}" : "Only groups matching the whitelist regex will be created, updated and deleted by the group claim. For example: {regex} allows all groups which ID starts with {substr}",
    "Restrict login for users that are not in any whitelisted group" : "Restrict login for users that are not in any whitelisted group",
    "Users that are not part of any whitelisted group are not created and can not login" : "Users that are not part of any whitelisted group are not created and can not login",
    "Check Bearer token on API and WebDAV requests" : "Check Bearer token on API and WebDAV requests",
    "Do you want to allow API calls and WebDAV request that are authenticated with an OIDC ID token or access token?" : "Do you want to allow API calls and WebDAV request that are authenticated with an OIDC ID token or access token?",
    "Auto provision user when accessing API and WebDAV with Bearer token" : "Auto provision user when accessing API and WebDAV with Bearer token",
    "This automatically provisions the user, when sending API and WebDAV requests with a Bearer token. Auto provisioning and Bearer token check have to be activated for this to work." : "This automatically provisions the user, when sending API and WebDAV requests with a Bearer token. Auto provisioning and Bearer token check have to be activated for this to work.",
    "Send ID token hint on logout" : "Send ID token hint on logout",
    "Should the ID token be included as the id_token_hint GET parameter in the OpenID logout URL? Users are redirected to this URL after logging out of Nextcloud. Enabling this setting exposes the OIDC ID token to the user agent, which may not be necessary depending on the OIDC provider." : "Should the ID token be included as the id_token_hint GET parameter in the OpenID logout URL? Users are redirected to this URL after logging out of Nextcloud. Enabling this setting exposes the OIDC ID token to the user agent, which may not be necessary depending on the OIDC provider.",
    "Cancel" : "Cancel",
    "Domain" : "Domain",
    "your.domain" : "your.domain"
},
"nplurals=2; plural=(n != 1);");
