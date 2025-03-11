OC.L10N.register(
    "user_oidc",
    {
    "Login with %1s" : "Anmelden mit %1s",
    "ID4Me is disabled" : "ID4Me ist deaktiviert",
    "Invalid OpenID domain" : "Ungültige OpenID-Domäne",
    "Invalid authority issuer" : "Ungültiger Autoritätsaussteller",
    "Multiple authority found" : "Mehrere Autoritäten gefunden",
    "The received state does not match the expected value." : "Der empfangene Status stimmt nicht mit dem erwarteten Wert überein.",
    "Authority not found" : "Autorität nicht gefunden",
    "Failed to decrypt the ID4ME provider client secret" : "Fehler beim Entschlüsseln des ID4ME-Provider-Client-Geheimnisses ",
    "The received token is expired." : "Das empfangene Token ist abgelaufen.",
    "The audience does not match ours" : "Die Audience passt nicht zu unserer",
    "The authorized party does not match ours" : "Die autorisierte Partei stimmt nicht mit unserer überein",
    "No authorized party" : "Keine autorisierte Partei",
    "The nonce does not match" : "Die Nonce stimmt nicht überein",
    "You must access Nextcloud with HTTPS to use OpenID Connect." : "Für die Verwendung von OpenID Connect muss der Zugriff auf Nextcloud mit HTTPS erfolgen.",
    "There is not such OpenID Connect provider." : "Einen solchen OpenID Connect-Anbieter gibt es nicht.",
    "Could not reach the OpenID Connect provider." : "Der OpenID Connect-Anbieter konnte nicht erreicht werden.",
    "Failed to decrypt the OIDC provider client secret" : "Das Client-Geheimnis des OIDC-Providers konnte nicht entschlüsselt werden.",
    "Failed to contact the OIDC provider token endpoint" : "Der Kontakt zum Token-Endpunkt des OIDC-Providers ist fehlgeschlagen",
    "The issuer does not match the one from the discovery endpoint" : "Der Kontakt zum Token-Endpunkt des OIDC-Providers ist fehlgeschlagen",
    "Failed to provision the user" : "Der Benutzer konnte nicht bereitgestellt werden",
    "You do not have permission to log in to this instance. If you think this is an error, please contact an administrator." : "Sie haben keine Berechtigung, sich bei dieser Instanz anzumelden. Wenn Sie glauben, dass dies ein Fehler ist, wenden Sie sich bitte an die Administration.",
    "User conflict" : "Benutzerkonflikt",
    "OpenID Connect" : "OpenID Connect",
    "OpenID Connect user backend" : "OpenID Connect-Benutzer-Backend",
    "Use an OpenID Connect backend to login to your Nextcloud" : "Ein OpenID Connect-Backend verwenden, um sich bei Ihrer Nextcloud anzumelden",
    "Allows flexible configuration of an OIDC server as Nextcloud login user backend." : "Ermöglicht die flexible Konfiguration eines OIDC-Servers als Nextcloud-Anmeldebenutzer-Backend.",
    "Could not save ID4me state: {msg}" : "ID4me-Status konnte nicht gespeichert werden: {msg}",
    "Could not update the provider:" : "Der Anbieter konnte nicht aktualisiert werden:",
    "Could not remove provider: {msg}" : "Anbieter konnte nicht entfernt werden: {msg}",
    "Could not register provider:" : "Anbieter konnte nicht registriert werden:",
    "Allows users to authenticate via OpenID Connect providers." : "Ermöglicht Benutzern die Authentifizierung über OpenID Connect-Anbieter.",
    "Enable ID4me" : "ID4me aktivieren",
    "Registered Providers" : "Registrierte Anbieter",
    "Register new provider" : "Neuen Anbieter registrieren",
    "Register a new provider" : "Einen neuen Anbieter registrieren",
    "Configure your provider to redirect back to {url}" : "Den Provider so konfigurieren, dass er nach {url} zurückleitet",
    "No providers registered." : "Keine Anbieter registriert.",
    "Client ID" : "Client-ID",
    "Discovery endpoint" : "Erkennungsendpunkt",
    "Backchannel Logout URL" : "Rückkanal-Abmelde-URL",
    "Redirect URI (to be authorized in the provider client configuration)" : "Umleitungs-URI (muss in der Client-Konfiguration des Providers autorisiert werden)",
    "Update" : "Aktualisieren",
    "Remove" : "Entfernen",
    "Update provider settings" : "Anbietereinstellungen aktualisieren",
    "Update provider" : "Anbieter aktualisieren",
    "Submit" : "Übermitteln",
    "Client configuration" : "Client-Konfiguration",
    "Identifier (max 128 characters)" : "Kennung (max. 128 Zeichen)",
    "Display name to identify the provider" : "Anzeigename zur Identifizierung des Anbieters",
    "Client secret" : "Geheime Zeichenkette des Clients",
    "Leave empty to keep existing" : "Leer lassen um vorhandene zu behalten",
    "Warning, if the protocol of the URLs in the discovery content is HTTP, the ID token will be delivered through an insecure connection." : "Achtung: Wenn das Protokoll der URLs im Discovery-Inhalt HTTP ist, wird das ID-Token über eine unsichere Verbindung übermittelt.",
    "Custom end session endpoint" : "Benutzerdefinierter Endpunkt zum Beenden der Sitzung",
    "Scope" : "Bereich",
    "Extra claims" : "Extra Claims",
    "Attribute mapping" : "Attribute-Mapping",
    "User ID mapping" : "Benutzer-ID-Mapping",
    "Quota mapping" : "Kontingent-Mapping",
    "Groups mapping" : "Gruppen-Mapping",
    "Extra attributes mapping" : "Extra-Attribute-Mapping",
    "Display name mapping" : "Anzeigename-Mapping",
    "Gender mapping" : "Geschlechts-Mapping",
    "Email mapping" : "E-Mail-Mapping",
    "Phone mapping" : "Telefon-Mapping",
    "Language mapping" : "Sprach-Mapping",
    "Role/Title mapping" : "Rollen/Titel--Mapping",
    "Street mapping" : "Straßen-Mapping",
    "Postal code mapping" : "Postleitzahl-Mapping",
    "Locality mapping" : "Orts-Mapping",
    "Region mapping" : "Regions-Mapping",
    "Country mapping" : "Land-Mapping",
    "Organisation mapping" : "Organisations-Mapping",
    "Website mapping" : "Webseiten-Mapping",
    "Avatar mapping" : "Avatar-Mapping",
    "Biography mapping" : "Biographie-Mapping",
    "Twitter mapping" : "Twitter-Mapping",
    "Fediverse/Nickname mapping" : "Fediverse/Spitznamen-Mapping",
    "Headline mapping" : "Überschrift-Mapping",
    "Authentication and Access Control Settings" : "Authentifizierungs- und Zugriffskontrolleinstellungen",
    "Use unique user id" : "Eindeutige Benutzer-ID verwenden",
    "By default every user will get a unique userid that is a hashed value of the provider and user id. This can be turned off but uniqueness of users accross multiple user backends and providers is no longer preserved then." : "Standardmäßig erhält jeder Benutzer eine eindeutige Benutzer-ID, die ein Hashwert des Anbieters und der Benutzer-ID ist. Dies kann deaktiviert werden, allerdings bleibt die Eindeutigkeit der Benutzer über mehrere Benutzer-Backends und Anbieter hinweg dann nicht mehr erhalten.",
    "Use provider identifier as prefix for ids" : "Providerkennung als Präfix für IDs verwenden",
    "To keep ids in plain text, but also preserve uniqueness of them across multiple providers, a prefix with the providers name is added." : "Um die IDs im Klartext zu belassen, aber auch ihre Eindeutigkeit über mehrere Anbieter hinweg zu wahren, wird ein Präfix mit dem Namen des Anbieters hinzugefügt.",
    "Use group provisioning." : "Gruppenbereitstellung verwenden.",
    "This will create and update the users groups depending on the groups claim in the id token. The Format of the groups claim value should be {sample1} or {sample2} or {sample3}" : "Hierdurch werden die Benutzergruppen abhängig vom Gruppenanspruch im ID-Token erstellt und aktualisiert. Das Format des Gruppenanspruchswerts sollte {sample1} oder {sample2} oder {sample3} sein.",
    "Group whitelist regex" : "Gruppen-Whitelist-Regex",
    "Only groups matching the whitelist regex will be created, updated and deleted by the group claim. For example: {regex} allows all groups which ID starts with {substr}" : "Nur Gruppen, die dem regulären Ausdruck der Whitelist entsprechen, werden vom Gruppen-Claim erstellt, aktualisiert und gelöscht. Beispiel: {regex} erlaubt alle Gruppen, deren ID mit {substr} beginnt",
    "Restrict login for users that are not in any whitelisted group" : "Die Anmeldung für Benutzer, die sich nicht in einer Whitelist-Gruppe befinden, beschränken",
    "Users that are not part of any whitelisted group are not created and can not login" : "Benutzer, die keiner Whitelist-Gruppe angehören, werden nicht erstellt und können sich nicht anmelden",
    "Check Bearer token on API and WebDav requests" : "Bearer-Token bei API- und WebDav-Anfragen überprüfen",
    "Do you want to allow API calls and WebDav request that are authenticated with an OIDC ID token or access token?" : "Sollen API-Aufrufe und WebDav-Anfragen zugelassen werden, die mit einem OIDC-ID-Token oder Zugriffstoken authentifiziert werden?",
    "Auto provision user when accessing API and WebDav with Bearer token" : "Automatische Benutzerbereitstellung beim Zugriff auf API und WebDav mit Bearer-Token",
    "This automatically provisions the user, when sending API and WebDav Requests with a Bearer token. Auto provisioning and Bearer token check have to be activated for this to work." : "Dadurch wird der Benutzer automatisch bereitgestellt, wenn API- und WebDav-Anfragen mit einem Bearer-Token gesendet werden. Damit dies funktioniert, müssen die automatische Bereitstellung und die Bearer-Token-Prüfung aktiviert sein.",
    "Send ID token hint on logout" : "Beim Abmelden einen ID-Token-Hinweis senden",
    "Should the ID token be included as the id_token_hint GET parameter in the OpenID logout URL? Users are redirected to this URL after logging out of Nextcloud. Enabling this setting exposes the OIDC ID token to the user agent, which may not be necessary depending on the OIDC provider." : "Soll das ID-Token als GET-Parameter id_token_hint in die OpenID-Abmelde-URL aufgenommen werden? Benutzer werden nach der Abmeldung von Nextcloud zu dieser URL umgeleitet. Durch Aktivieren dieser Einstellung wird das OIDC-ID-Token dem Benutzeragenten zugänglich gemacht, was je nach OIDC-Anbieter möglicherweise nicht erforderlich ist.",
    "Cancel" : "Abbrechen",
    "Domain" : "Domain",
    "your.domain" : "your.domain"
},
"nplurals=2; plural=(n != 1);");
