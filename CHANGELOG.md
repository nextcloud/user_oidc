# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).


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

