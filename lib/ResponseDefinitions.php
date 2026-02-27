<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\UserOIDC;

/**
 * @psalm-type UserOIDCProviderSettings = array{
 *     mappingDisplayName: string,
 *     mappingEmail: string,
 *     mappingQuota: string,
 *     mappingUid: string,
 *     mappingGroups: string,
 *     mappingLanguage: string,
 *     mappingLocale: string,
 *     mappingAddress: string,
 *     mappingStreetaddress: string,
 *     mappingPostalcode: string,
 *     mappingLocality: string,
 *     mappingRegion: string,
 *     mappingCountry: string,
 *     mappingWebsite: string,
 *     mappingAvatar: string,
 *     mappingTwitter: string,
 *     mappingFediverse: string,
 *     mappingOrganisation: string,
 *     mappingRole: string,
 *     mappingHeadline: string,
 *     mappingBiography: string,
 *     mappingPhonenumber: string,
 *     mappingGender: string,
 *     mappingPronouns: string,
 *     mappingBirthdate: string,
 *     uniqueUid: bool,
 *     checkBearer: bool,
 *     sendIdTokenHint: bool,
 *     bearerProvisioning: bool,
 *     extraClaims: string,
 *     providerBasedId: bool,
 *     groupProvisioning: bool,
 *     groupWhitelistRegex: string,
 *     restrictLoginToGroups: bool,
 *     nestedAndFallbackClaims: bool,
 * }
 *
 * @psalm-type UserOIDCProvider = array{
 *     id: int,
 *     identifier: string,
 *     clientId: string,
 *     discoveryEndpoint: ?string,
 *     endSessionEndpoint: ?string,
 *     postLogoutUri: ?string,
 *     scope: string,
 *     settings: UserOIDCProviderSettings,
 * }
 */
class ResponseDefinitions {
}
