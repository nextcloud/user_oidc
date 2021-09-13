# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

