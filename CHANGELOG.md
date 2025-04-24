<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 7.2.0 – 2025-04-24

### Added

- Provider-specific setting to enable support for nested claims and fallback attribute mapping @dragonpil [#1103](https://github.com/nextcloud/user_oidc/pull/1103)

### Changed

- Allow requesting scopes when using ExchangedTokenRequestedEvent event @saw-jan [#1099](https://github.com/nextcloud/user_oidc/pull/1099)
- Allow requesting scopes when using InternalTokenRequestedEvent event @saw-jan [#1098](https://github.com/nextcloud/user_oidc/pull/1098)
- Make settings form footer sticky @julien-nc [#1107](https://github.com/nextcloud/user_oidc/pull/1107)

### Fixed

- Fix serialization of requested claims to avoid empty arrays resulting in JSON arrays instead of objects @julien-nc [#1093](https://github.com/nextcloud/user_oidc/pull/1093)
- Fix grammar @rakekniven [#1104](https://github.com/nextcloud/user_oidc/pull/1104)

## 7.1.0 – 2025-03-24

### Changed

- Clarify token request events @julien-nc [#1082](https://github.com/nextcloud/user_oidc/pull/1082)

### Fixed

- Fix translation issues @julien-nc [#1075](https://github.com/nextcloud/user_oidc/pull/1075)
- Improve grammar @rakekniven [1083](https://github.com/nextcloud/user_oidc/pull/1083)

## 7.0.0 – 2025-03-07

### Added

- Add ability to set a custom login button label @julien-nc [#1070](https://github.com/nextcloud/user_oidc/pull/1070)
- Add support for bearer token validation and generation by the OIDC Identity Provider app via events @julien-nc [#1040](https://github.com/nextcloud/user_oidc/pull/1040)

### Changed

- Prepare for transifex sync @julien-nc [#1071](https://github.com/nextcloud/user_oidc/pull/1071)
- Remove AZP check when validating a bearer ID token @julien-nc [#1039](https://github.com/nextcloud/user_oidc/pull/1039)
- Bump min NC version to 28 to make sure we have `OCP\Authentication\Token\IToken` @julien-nc [#1061](https://github.com/nextcloud/user_oidc/pull/1061)

### Fixed

- Properly avoid password confirmation with user_oidc by adding the SCOPE_SKIP_PASSWORD_VALIDATION scope to the session tokens @julien-nc [#1061](https://github.com/nextcloud/user_oidc/pull/1061)
- Fix scope for the role account property @julien-nc [#1069](https://github.com/nextcloud/user_oidc/pull/1069)

## 6.3.1 – 2025-02-25

### Fixed

- Add missing group-provisioning options in upsert command @bjalbor [#1063](https://github.com/nextcloud/user_oidc/pull/1063)
- Dispatch prelogin event before login event @ArtificialOwl [#1065](https://github.com/nextcloud/user_oidc/pull/1065)

## 6.3.0 – 2025-02-17

### Changed

- Support NC 32 @nickvergessen [#1029](https://github.com/nextcloud/user_oidc/pull/1029)
- Map the user language @julien-nc [#1046](https://github.com/nextcloud/user_oidc/pull/1046)
- Adjustment for GSS @julien-nc [#1053](https://github.com/nextcloud/user_oidc/pull/1053)

### Fixed

- Replace broken jumpstart docs link @joshtrichards [#1045](https://github.com/nextcloud/user_oidc/pull/1045)
- Fetch default privacy scopes and set properties appropriate @bjalbor [#1048](https://github.com/nextcloud/user_oidc/pull/1048)
- Backchannel logout token may not contain "sub" @prigaux [#1049](https://github.com/nextcloud/user_oidc/pull/1049)
- Fix '"kid" invalid, unable to lookup correct key' when keys are rotated @Adphi [#1035](https://github.com/nextcloud/user_oidc/pull/1035)
- Fix(ProvisioningService): Handle `InvalidArgumentException` when updating account @susnux [#1058](https://github.com/nextcloud/user_oidc/pull/1058)

## 6.2.1 – 2025-01-23

### Fixed

- Fix crash when storing a token without refresh_expires_in or refresh_token @julien-nc [#1025](https://github.com/nextcloud/user_oidc/pull/1025)
- Disable token exchange mechanism by default @julien-nc [#1025](https://github.com/nextcloud/user_oidc/pull/1025)

## 6.2.0 – 2025-01-20

### Added

- Support for Global Scale (globalsiteselector app) @julien-nc [#1011](https://github.com/nextcloud/user_oidc/pull/1011)
- Add whitelist regular expression for group provisioning @bergerar [#884](https://github.com/nextcloud/user_oidc/pull/884)
- Optionally restrict login to users matching a certain group @bergerar [#884](https://github.com/nextcloud/user_oidc/pull/884)
- Token exchange mechanism for other apps @julien-nc [#974](https://github.com/nextcloud/user_oidc/pull/974)
- Password confirmation in admin settings @janepie [#991](https://github.com/nextcloud/user_oidc/pull/991)
- Add option to configure bearer provisioning via occ @janepie [#1003](https://github.com/nextcloud/user_oidc/pull/1003)
- Add config value to make the email match optional when searching for a user or a display name @julien-nc [#1014](https://github.com/nextcloud/user_oidc/pull/1014)

### Changed

- Make the app Reuse compliant @AndyScherzinger [#975](https://github.com/nextcloud/user_oidc/pull/975)
- Add support for comma-separated groups in group mapping attribute @julien-nc [#1006](https://github.com/nextcloud/user_oidc/pull/1006)

### Fixed

- Update cache when discovery endpoint is changed @janepie [#1002](https://github.com/nextcloud/user_oidc/pull/1002)
- Set fallback redirect URL for login if already logged in @janepie [#1001](https://github.com/nextcloud/user_oidc/pull/1001)
- Fix redirect URI when Nextcloud is accessed at a sub path @bdovaz [#990](https://github.com/nextcloud/user_oidc/pull/990)
- Handle redirect URL containing a ':' @artonge [#1008](https://github.com/nextcloud/user_oidc/pull/1008)
- Avoid slow queries in scenarios where we do not need a search @juliusknorr [#1019](https://github.com/nextcloud/user_oidc/pull/1019)
- Adjust provisioning service to correctly update the display name on login @julien-nc [#979](https://github.com/nextcloud/user_oidc/pull/979)

## 6.1.2 – 2024-10-30

### Fixed

- Fix state token missing while trying to login using Nextcloud Desktop (login flow) @joselameira [#971](https://github.com/nextcloud/user_oidc/pull/971)

## 6.1.1 – 2024-10-22

### Fixed

- Ensure providerClientId is declared when validating bearer tokens @artonge [#969](https://github.com/nextcloud/user_oidc/pull/969)

## 6.1.0 – 2024-10-15

### Added

- feat(provisioning): New system config flag to disable user creation in soft auto provisioning @julien-nc [#954](https://github.com/nextcloud/user_oidc/pull/954)
- feat(ApiController): Add endpoint to de-provision user @edward-ly [#960](https://github.com/nextcloud/user_oidc/pull/960)
- Add an OCS API controller for pre-provisioning and de-provisioning @julien-nc [#963](https://github.com/nextcloud/user_oidc/pull/963)

### Changed

- Make aud and azp checks optional when logging in or validating a bearer token @julien-nc [#921](https://github.com/nextcloud/user_oidc/pull/921)
- Bump max NC version to 31

### Fixed

- Fix provisioning mistake when setting role @julien-nc [#930](https://github.com/nextcloud/user_oidc/pull/930)
- Fix LoginController: revert default `token_endpoint_auth_method` value @edward-ly [#946](https://github.com/nextcloud/user_oidc/pull/946)
- Fix integration tests sometimes not finding docker-compose but 'docker compose' @julien-nc [#953](https://github.com/nextcloud/user_oidc/pull/953)
- Backchannel logout endpoint should only return 200 or 400 @julien-nc [#955](https://github.com/nextcloud/user_oidc/pull/955)
- Use correct userId when getting user folder in provisioning endpoint if unique-uid is enabled @julien-nc [#958](https://github.com/nextcloud/user_oidc/pull/958)
- Re-enable PKCE by default (if supported by the IdP) @edward-ly [#956](https://github.com/nextcloud/user_oidc/pull/956)
- Prevent redirecting to an absolute URL after login @julien-nc [#961](https://github.com/nextcloud/user_oidc/pull/961)
- Fix provisioning: If address attr is an object but can't be parsed to an array, give null to the 'attr mapped' event @julien-nc [#948](https://github.com/nextcloud/user_oidc/pull/948)

## 6.0.1 – 2024-07-26

### Fixed

- Many fixes in ProvisioningServer @julien-nc [#905](https://github.com/nextcloud/user_oidc/pull/905)

## 6.0.0 – 2024-07-23

### Changed

- Update npm pkgs
- Use nextcloud/vue 8.15.0
- Support more token endpoint authentication methods @xataxxx [#897](https://github.com/nextcloud/user_oidc/pull/897)

### Fixed

- Set avatar on login @julien-nc [#838](https://github.com/nextcloud/user_oidc/pull/838)
- Fix small accessibility issue with NcModal @julien-nc

## 5.0.3 – 2024-06-21

### Added

- Support search by email in the user backend @tcoupin [#815](https://github.com/nextcloud/user_oidc/pull/815)

### Changed

- Improve the stub so it's not confusing IDEs @nickvergessen @julien-nc [#862](https://github.com/nextcloud/user_oidc/pull/862) [#863](https://github.com/nextcloud/user_oidc/pull/863)
- Set group displayname when provisioning @towo @julien-nc [#880](https://github.com/nextcloud/user_oidc/pull/880)
- Add issuer, audience and azp checks in bearer token validator @julien-nc [#864](https://github.com/nextcloud/user_oidc/pull/864)
- Allow to disable default quota, displayName, groups and email claims @julien-nc [#883](https://github.com/nextcloud/user_oidc/pull/883)

### Fixed

- Fix, improve and refactor the upsert occ command @julien-nc [#860](https://github.com/nextcloud/user_oidc/pull/860)
- Fix biography attr being used to set the account gender @julien-nc [#888](https://github.com/nextcloud/user_oidc/pull/888)

## 5.0.2 – 2024-03-18

### Changed

- Update npm packages

### Fixed

- Stop using missing OC::->getEventDispatcher method (dropped in NC 28) @julien-nc [#818](https://github.com/nextcloud/user_oidc/pull/818)

## 5.0.1 – 2024-02-28

### Added

- Soft auto-provisioning @julien-nc [#730](https://github.com/nextcloud/user_oidc/pull/730)

### Fixed

- Prevent using ID4ME routes if ID4ME is disabled @julien-nc
- Fix(login): user get null check @skjnldsv [#789](https://github.com/nextcloud/user_oidc/pull/789)

## 1.3.6 – 2024-01-29

### Added

- Customizeable end session endpoint @nc-fkl [#724](https://github.com/nextcloud/user_oidc/pull/724)
- Implement ICountUsersBackend to give a user count in 'occ user:report' @julien-nc [#733](https://github.com/nextcloud/user_oidc/pull/733)
- Many additional user attribute mapping @nc-fkl [#729](https://github.com/nextcloud/user_oidc/pull/729)
- Psalm checks @julien-nc [#765](https://github.com/nextcloud/user_oidc/pull/765)
- Ensure the discovery endpoint result is valid @nc-fkl [#750](https://github.com/nextcloud/user_oidc/pull/750)

### Changed

- Bump max NC version to 29 @julien-nc [#717](https://github.com/nextcloud/user_oidc/pull/717)
- Bump min NC version to 25 @julien-nc [#765](https://github.com/nextcloud/user_oidc/pull/765)
- Increased database column length for client id and secret @nc-fkl [#711](https://github.com/nextcloud/user_oidc/pull/711)
- Make PKCE optional @julien-nc [#740](https://github.com/nextcloud/user_oidc/pull/740)
- Update nextcloud/vue to v8 @julien-nc [#763](https://github.com/nextcloud/user_oidc/pull/763)

### Fixed

- Avoid a lot of error log on token validation failure @aro-lew [#721](https://github.com/nextcloud/user_oidc/pull/721)
- Avoid identifier edition when editing a provider @nc-fkl [#714](https://github.com/nextcloud/user_oidc/pull/714)

## 1.3.5 – 2023-11-24

### Added

- PKCE support [#697](https://github.com/nextcloud/user_oidc/pull/697) @rullzer @nc-fkl

### Changed

- improve id4me token validation [#715](https://github.com/nextcloud/user_oidc/pull/715) @julien-nc

### Fixed

- fix potentially missing alg in jwks [#713](https://github.com/nextcloud/user_oidc/pull/713) @julien-nc

## 1.3.4

### Changed

- Bump min NC to 24 @julien-nc [#675](https://github.com/nextcloud/user_oidc/pull/675)
- Upgrade php-jwt, adjust implementation @julien-nc [#675](https://github.com/nextcloud/user_oidc/pull/675)

### Fixed

- Disable password confirmation for SSO @juliushaertl [#668](https://github.com/nextcloud/user_oidc/pull/668)

## 1.3.3

### Changed

- Add issuer and azp validation, improve audience validation @julien-nc [#642](https://github.com/nextcloud/user_oidc/pull/642)
- Encrypt stored oidc provider client secrets and id4me client secrets @julien-nc [#636](https://github.com/nextcloud/user_oidc/pull/636)

## 1.3.2

### Fixed

- fix Oracle database support by avoiding empty strings that are replaced with null @julien-nc [#563](https://github.com/nextcloud/user_oidc/pull/563)
- use more recent Ubuntu image for PhpUnit tests as the old ones are not picked up by runners @julien-nc [#619](https://github.com/nextcloud/user_oidc/pull/619)
- better error handling and throttling in Id4Me and login controllers @julien-nc [#615](https://github.com/nextcloud/user_oidc/pull/615) [#618](https://github.com/nextcloud/user_oidc/pull/618)

### Other

- show redirect URI to help configuring the client on the provider side @julien-nc [#598](https://github.com/nextcloud/user_oidc/pull/598)
- add Nextcloud 27 support @julien-nc [#616](https://github.com/nextcloud/user_oidc/pull/616)

## 1.3.1

### Fixed

- fix id4me/id4me-rp imports @julien-nc [#585](https://github.com/nextcloud/user_oidc/pull/585)
- don't include .nextcloudignore in app releases @julien-nc [#595](https://github.com/nextcloud/user_oidc/pull/595)
- avoid using IUserManager::getDisplayName that was introduced in NC 25 @julien-nc [#594](https://github.com/nextcloud/user_oidc/pull/594)

## 1.3.0

### Added

- Group provisioning @MarvinOehlerkingCap [#502](https://github.com/nextcloud/user_oidc/pull/502)
- Group mapping @MarvinOehlerkingCap [#502](https://github.com/nextcloud/user_oidc/pull/502)
- Prefix user ID with provider ID @MarvinOehlerkingCap [#502](https://github.com/nextcloud/user_oidc/pull/502)
- User provisioning on API requests authenticated with a Bearer token @MarvinOehlerkingCap [#502](https://github.com/nextcloud/user_oidc/pull/502)
- DiscoveryService tests @julien-nc [#518](https://github.com/nextcloud/user_oidc/pull/518)

### Fixed

- Expected code being exposed when the received one does not match @julien-nc [#580](https://github.com/nextcloud/user_oidc/pull/580)
- Non-unique database indexes @julien-nc [#541](https://github.com/nextcloud/user_oidc/pull/541)
- User display name change propagation @julien-nc [#530](https://github.com/nextcloud/user_oidc/pull/530)
- Fix discovery URL generation with GET parameters @julien-nc [#518](https://github.com/nextcloud/user_oidc/pull/518)

### Other

- Safer user sync with LDAP user provisioning @julien-nc [#535](https://github.com/nextcloud/user_oidc/pull/535)

## 1.2.1

### Added

- Support for Nextcloud 26 @nickvergessen [#504](https://github.com/nextcloud/user_oidc/pull/504)
- Support backchannel logout @julien-nc [#464](https://github.com/nextcloud/user_oidc/pull/464)
- New endpoint to pre-provision users @julien-nc [#450](https://github.com/nextcloud/user_oidc/pull/450)
- Create and populate user storage if necessary on bearer token validation @julien-nc [#443](https://github.com/nextcloud/user_oidc/pull/443)

### Fixed

- Fix crash on bearer token validation before first login @julien-nc [#498](https://github.com/nextcloud/user_oidc/pull/498)
- Potential XSS with Safari @julien-nc [#496](https://github.com/nextcloud/user_oidc/pull/496)
- Fix single logout when using Keycloak >= 18 @ubipo [#493](https://github.com/nextcloud/user_oidc/pull/493)
- Enforce HTTPS @julien-nc [#495](https://github.com/nextcloud/user_oidc/pull/495)
- Check if user was deleted in LDAP if necessary @julien-nc [#451](https://github.com/nextcloud/user_oidc/pull/451)
- Perform a user search before login to make sure LDAP users are synced @julien-nc [#436](https://github.com/nextcloud/user_oidc/pull/436)
- Make sure the user avatar is generated on login @julien-nc [#437](https://github.com/nextcloud/user_oidc/pull/437)
- Fix upsert command resetting the scope if none provided @julien-nc [#433](https://github.com/nextcloud/user_oidc/pull/433)
- Fix upsert command not printing the provider when no parameter given @julien-nc [#431](https://github.com/nextcloud/user_oidc/pull/431)
- Fix single logout with non-auto provisioned users @julien-nc [#429](https://github.com/nextcloud/user_oidc/pull/429)

### Other

- Modernize settings frontend (use `@nextcloud/vue`, bump js libs...) @julien-nc [#497](https://github.com/nextcloud/user_oidc/pull/497)

## 1.2.0

### Added

- Fix and polish upsert and delete commands @eneiluj [#338](https://github.com/nextcloud/user_oidc/pull/338)
- Remove redundant and time consuming userinfo validation @eneiluj [#334](https://github.com/nextcloud/user_oidc/pull/334)
- Cache provider public keys @eneiluj [#337](https://github.com/nextcloud/user_oidc/pull/337)
- Move to IBootstrap @juliushaertl [#385](https://github.com/nextcloud/user_oidc/pull/385)
- New system config to disable SelfEncodedValidator bearer token validator @eneiluj [#372](https://github.com/nextcloud/user_oidc/pull/372)
- Dispatch new event when a bearer token is validated @eneiluj [#381](https://github.com/nextcloud/user_oidc/pull/381)
- Add new provider setting to request extra claims @eneiluj [#407](https://github.com/nextcloud/user_oidc/pull/407)
- Implement single logout @eneiluj [#373](https://github.com/nextcloud/user_oidc/pull/373)

### Fixed

- Avoid claiming 'sub', display code response error @eneiluj [#329](https://github.com/nextcloud/user_oidc/pull/329)
- Optionally keep userinfo validator for api calls only, use all providers @eneiluj [#335](https://github.com/nextcloud/user_oidc/pull/335)
- Let .nextcloudignore skip defined paths only in root @juliushaertl [#353](https://github.com/nextcloud/user_oidc/pull/353)
- Avoid empty session on certain redirect situations in Safari @juliushaertl [#358](https://github.com/nextcloud/user_oidc/pull/358)
- Cache discovery endpoint results @juliushaertl [#367](https://github.com/nextcloud/user_oidc/pull/367)
- Fix a small php 8 compatibility issue @CarlSchwan [#406](https://github.com/nextcloud/user_oidc/pull/406)
- Cache user object when checking existance @CarlSchwan [#412](https://github.com/nextcloud/user_oidc/pull/412)
- Ensure that a remember me cookie is created @juliushaertl [#425](https://github.com/nextcloud/user_oidc/pull/425)


## v1.1.0

### Added

- #304 Allow to disable other login methods
- #306 Add integration tests with keycloak
- #317 Claim handling and complex mapping rules @tsdicloud
- #320 Bearer token validation

### Fixed

- #303 Properly handle redirect after login
- #319 Fix typo in quota attribute @rgfernandes
- #316 Fix provider edition
- #314 Fix header/column label missmatch @alerque

### Other

- Dependency updates



## [v1.0.0](https://github.com/nextcloud/user_oidc/tree/v1.0.0) (2021-08-03)

[Full Changelog](https://github.com/nextcloud/user_oidc/compare/v0.3.2...v1.0.0)

**Implemented enhancements:**

- Add provider admin commands [\#292](https://github.com/nextcloud/user_oidc/pull/292) ([tsdicloud](https://github.com/tsdicloud))
- Move to npm7 and update actions [\#286](https://github.com/nextcloud/user_oidc/pull/286) ([skjnldsv](https://github.com/skjnldsv))
- Custom attribute mappings [\#268](https://github.com/nextcloud/user_oidc/pull/268) ([juliushaertl](https://github.com/juliushaertl))
- Implement missing user backend methods [\#267](https://github.com/nextcloud/user_oidc/pull/267) ([juliushaertl](https://github.com/juliushaertl))
- Update webpack config and add settings icon [\#259](https://github.com/nextcloud/user_oidc/pull/259) ([skjnldsv](https://github.com/skjnldsv))

**Fixed bugs:**

- Move mozart out of regular dependencies [\#296](https://github.com/nextcloud/user_oidc/pull/296) ([juliushaertl](https://github.com/juliushaertl))

## [0.3.1] - 2021-02-02
### Fixed
- Make column explitly nullable

## [0.3.0] - 2021-02-02
### Added
- NC 21 support

### Fixed
- Installing on NC20

## [0.1.0] - 2020-04-29
### Added
- Basic implementation of OIDC client
- Expirimental support for ID4ME

