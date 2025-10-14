<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<form class="provider-edit">
		<h3><b>{{ t('user_oidc', 'Client configuration') }}</b></h3>
		<p>
			<label for="oidc-identifier" :class="{ warning: identifierLength >= maxIdentifierLength }">{{ t('user_oidc', 'Identifier (max 128 characters)') }}</label>
			<input id="oidc-identifier"
				v-model="localProvider.identifier"
				type="text"
				:placeholder="t('user_oidc', 'Display name to identify the provider')"
				:disabled="identifierInitiallySet"
				required
				:maxlength="maxIdentifierLength">
		</p>
		<p>
			<label for="oidc-client-id">{{ t('user_oidc', 'Client ID') }}</label>
			<input id="oidc-client-id"
				v-model="localProvider.clientId"
				type="text"
				required>
		</p>
		<p>
			<label for="oidc-client-secret">{{ t('user_oidc', 'Client secret') }}</label>
			<input id="oidc-client-secret"
				v-model="localProvider.clientSecret"
				:placeholder="update ? t('user_oidc', 'Leave empty to keep existing') : null"
				type="text"
				:required="!update"
				autocomplete="off">
		</p>
		<p class="settings-hint warning-hint">
			<AlertOutlineIcon :size="20" class="icon" />
			{{ t('user_oidc', 'Warning, if the protocol of the URLs in the discovery content is HTTP, the ID token will be delivered through an insecure connection.') }}
		</p>
		<p>
			<label for="oidc-discovery-endpoint">{{ t('user_oidc', 'Discovery endpoint') }}</label>
			<input id="oidc-discovery-endpoint"
				v-model="localProvider.discoveryEndpoint"
				type="text"
				required>
		</p>
		<p>
			<label for="oidc-end-session-endpoint">{{ t('user_oidc', 'Custom end session endpoint') }}</label>
			<input id="oidc-end-session-endpoint"
				v-model="localProvider.endSessionEndpoint"
				class="italic-placeholder"
				type="text"
				maxlength="255"
				placeholder="(Optional)">
		</p>
		<p>
			<label for="oidc-post-logout-uri">{{ t('user_oidc', 'Post logout URI') }}</label>
			<input id="oidc-post-logout-uri"
				v-model="localProvider.postLogoutUri"
				class="italic-placeholder"
				type="text"
				maxlength="255"
				placeholder="(Optional)">
		</p>
		<p>
			<label for="oidc-scope">{{ t('user_oidc', 'Scope') }}</label>
			<input id="oidc-scope"
				v-model="localProvider.scope"
				type="text"
				placeholder="openid email profile">
		</p>
		<p>
			<label for="oidc-extra-claims">{{ t('user_oidc', 'Extra claims') }}</label>
			<input id="oidc-extra-claims"
				v-model="localProvider.settings.extraClaims"
				type="text"
				placeholder="claim1 claim2 claim3">
		</p>
		<h3><b>{{ t('user_oidc', 'Attribute mapping') }}</b></h3>
		<NcCheckboxRadioSwitch
			v-model="localProvider.settings.nestedAndFallbackClaims"
			wrapper-element="div">
			{{ t('user_oidc', 'Enable nested and fallback claim mappings (like "{example}")', { example: 'custom.nickname | profile.name | name' }) }}
		</NcCheckboxRadioSwitch>
		<p>
			<label for="mapping-uid">{{ t('user_oidc', 'User ID mapping') }}</label>
			<input id="mapping-uid"
				v-model="localProvider.settings.mappingUid"
				type="text"
				placeholder="sub">
		</p>
		<p>
			<label for="mapping-quota">{{ t('user_oidc', 'Quota mapping') }}</label>
			<input id="mapping-quota"
				v-model="localProvider.settings.mappingQuota"
				type="text"
				placeholder="quota">
		</p>
		<p>
			<label for="mapping-groups">{{ t('user_oidc', 'Groups mapping') }}</label>
			<input id="mapping-groups"
				v-model="localProvider.settings.mappingGroups"
				type="text"
				placeholder="groups"
				:disabled="!localProvider.settings.groupProvisioning">
		</p>

		<h3>
			<NcButton variant="secondary" @click="toggleProfileAttributes">
				<template #icon>
					<ChevronRightIcon v-if="!showProfileAttributes" :size="20" />
					<ChevronDownIcon v-else :size="20" />
				</template>
				{{ t('user_oidc', 'Extra attributes mapping') }}
			</NcButton>
		</h3>

		<div v-show="showProfileAttributes" class="profile-attributes">
			<p>
				<label for="mapping-displayName">{{ t('user_oidc', 'Display name mapping') }}</label>
				<input id="mapping-displayName"
					v-model="localProvider.settings.mappingDisplayName"
					type="text"
					placeholder="name">
			</p>
			<p>
				<label for="mapping-birthdate">{{ t('user_oidc', 'Birth date mapping') }}</label>
				<input id="mapping-birthdate"
					v-model="localProvider.settings.mappingBirthdate"
					type="text"
					placeholder="birthdate">
			</p>
			<p>
				<label for="mapping-pronouns">{{ t('user_oidc', 'Pronouns mapping') }}</label>
				<input id="mapping-pronouns"
					v-model="localProvider.settings.mappingPronouns"
					type="text"
					placeholder="pronouns">
			</p>
			<p>
				<label for="mapping-gender">{{ t('user_oidc', 'Gender mapping') }}</label>
				<input id="mapping-gender"
					v-model="localProvider.settings.mappingGender"
					type="text"
					placeholder="gender">
			</p>
			<p>
				<label for="mapping-email">{{ t('user_oidc', 'Email mapping') }}</label>
				<input id="mapping-email"
					v-model="localProvider.settings.mappingEmail"
					type="text"
					placeholder="email">
			</p>
			<p>
				<label for="mapping-phone">{{ t('user_oidc', 'Phone mapping') }}</label>
				<input id="mapping-phone"
					v-model="localProvider.settings.mappingPhonenumber"
					type="text"
					placeholder="phone_number">
			</p>
			<p>
				<label for="mapping-language">{{ t('user_oidc', 'Language mapping') }}</label>
				<input id="mapping-language"
					v-model="localProvider.settings.mappingLanguage"
					type="text"
					placeholder="language">
			</p>
			<p>
				<label for="mapping-locale">{{ t('user_oidc', 'Locale mapping') }}</label>
				<input id="mapping-locale"
					v-model="localProvider.settings.mappingLocale"
					type="text"
					placeholder="locale">
			</p>
			<p>
				<label for="mapping-role">{{ t('user_oidc', 'Role/Title mapping') }}</label>
				<input id="mapping-role"
					v-model="localProvider.settings.mappingRole"
					type="text"
					placeholder="role">
			</p>
			<p>
				<label for="mapping-street_address">{{ t('user_oidc', 'Street mapping') }}</label>
				<input id="mapping-street_address"
					v-model="localProvider.settings.mappingStreetaddress"
					type="text"
					placeholder="street_address">
			</p>
			<p>
				<label for="mapping-postal_code">{{ t('user_oidc', 'Postal code mapping') }}</label>
				<input id="mapping-postal_code"
					v-model="localProvider.settings.mappingPostalcode"
					type="text"
					placeholder="postal_code">
			</p>
			<p>
				<label for="mapping-locality">{{ t('user_oidc', 'Locality mapping') }}</label>
				<input id="mapping-locality"
					v-model="localProvider.settings.mappingLocality"
					type="text"
					placeholder="locality">
			</p>
			<p>
				<label for="mapping-region">{{ t('user_oidc', 'Region mapping') }}</label>
				<input id="mapping-region"
					v-model="localProvider.settings.mappingRegion"
					type="text"
					placeholder="region">
			</p>
			<p>
				<label for="mapping-country">{{ t('user_oidc', 'Country mapping') }}</label>
				<input id="mapping-country"
					v-model="localProvider.settings.mappingCountry"
					type="text"
					placeholder="country">
			</p>
			<p>
				<label for="mapping-organisation">{{ t('user_oidc', 'Organisation mapping') }}</label>
				<input id="mapping-organisation"
					v-model="localProvider.settings.mappingOrganisation"
					type="text"
					placeholder="organisation">
			</p>
			<p>
				<label for="mapping-website">{{ t('user_oidc', 'Website mapping') }}</label>
				<input id="mapping-website"
					v-model="localProvider.settings.mappingWebsite"
					type="text"
					placeholder="website">
			</p>
			<p>
				<label for="mapping-avatar">{{ t('user_oidc', 'Avatar mapping') }}</label>
				<input id="mapping-avatar"
					v-model="localProvider.settings.mappingAvatar"
					type="text"
					placeholder="avatar">
			</p>
			<p>
				<label for="mapping-biography">{{ t('user_oidc', 'Biography mapping') }}</label>
				<input id="mapping-biography"
					v-model="localProvider.settings.mappingBiography"
					type="text"
					placeholder="biography">
			</p>
			<p>
				<label for="mapping-twitter">{{ t('user_oidc', 'X (formerly Twitter) mapping') }}</label>
				<input id="mapping-twitter"
					v-model="localProvider.settings.mappingTwitter"
					type="text"
					placeholder="twitter">
			</p>
			<p>
				<label for="mapping-fediverse">{{ t('user_oidc', 'Fediverse/Nickname mapping') }}</label>
				<input id="mapping-fediverse"
					v-model="localProvider.settings.mappingFediverse"
					type="text"
					placeholder="fediverse">
			</p>
			<p>
				<label for="mapping-headline">{{ t('user_oidc', 'Headline mapping') }}</label>
				<input id="mapping-headline"
					v-model="localProvider.settings.mappingHeadline"
					type="text"
					placeholder="headline">
			</p>
		</div>
		<h3><b>{{ t('user_oidc', 'Authentication and Access Control Settings') }}</b></h3>
		<NcCheckboxRadioSwitch
			v-model="localProvider.settings.uniqueUid"
			wrapper-element="div">
			{{ t('user_oidc', 'Use unique user ID') }}
		</NcCheckboxRadioSwitch>
		<p class="settings-hint">
			{{ t('user_oidc', 'By default every user will get a unique user ID that is a hashed value of the provider and user ID. This can be turned off but uniqueness of users accross multiple user backends and providers is no longer preserved then.') }}
		</p>
		<NcCheckboxRadioSwitch
			v-model="localProvider.settings.providerBasedId"
			wrapper-element="div">
			{{ t('user_oidc', 'Use provider identifier as prefix for IDs') }}
		</NcCheckboxRadioSwitch>
		<p class="settings-hint">
			{{ t('user_oidc', 'To keep IDs in plain text, but also preserve uniqueness of them across multiple providers, a prefix with the providers name is added.') }}
		</p>
		<NcCheckboxRadioSwitch
			v-model="localProvider.settings.groupProvisioning"
			wrapper-element="div">
			{{ t('user_oidc', 'Use group provisioning.') }}
		</NcCheckboxRadioSwitch>
		<p class="settings-hint">
			{{ t('user_oidc', 'This will create and update the users groups depending on the groups claim in the ID token. The Format of the groups claim value should be {sample1}, {sample2} or {sample3}', { sample1: '[{gid: "1", displayName: "group1"}, …]', sample2: '["group1", "group2", …]', sample3: '"group1,group2"' }, undefined, { escape: false }) }}
		</p>
		<p>
			<label for="group-whitelist-regex">{{ t('user_oidc', 'Group whitelist regex') }}</label>
			<input id="group-whitelist-regex"
				v-model="localProvider.settings.groupWhitelistRegex"
				type="text">
		</p>
		<p class="settings-hint">
			{{ t('user_oidc', 'Only groups matching the whitelist regex will be created, updated and deleted by the group claim. For example: {regex} allows all groups which ID starts with {substr}', { regex: '/^blue/', substr: 'blue' }) }}
		</p>
		<NcCheckboxRadioSwitch
			v-model="localProvider.settings.restrictLoginToGroups"
			wrapper-element="div">
			{{ t('user_oidc', 'Restrict login for users that are not in any whitelisted group') }}
		</NcCheckboxRadioSwitch>
		<p class="settings-hint">
			{{ t('user_oidc', 'Users that are not part of any whitelisted group are not created and can not login') }}
		</p>
		<NcCheckboxRadioSwitch
			v-model="localProvider.settings.checkBearer"
			wrapper-element="div">
			{{ t('user_oidc', 'Check Bearer token on API and WebDAV requests') }}
		</NcCheckboxRadioSwitch>
		<p class="settings-hint">
			{{ t('user_oidc', 'Do you want to allow API calls and WebDAV requests that are authenticated with an OIDC ID token or access token?') }}
		</p>
		<NcCheckboxRadioSwitch
			v-model="localProvider.settings.bearerProvisioning"
			wrapper-element="div"
			:disabled="!localProvider.settings.checkBearer">
			{{ t('user_oidc', 'Auto provision user when accessing API and WebDAV with Bearer token') }}
		</NcCheckboxRadioSwitch>
		<p class="settings-hint">
			{{ t('user_oidc', 'This automatically provisions the user, when sending API and WebDAV requests with a Bearer token. Auto provisioning and Bearer token check have to be activated for this to work.') }}
		</p>
		<NcCheckboxRadioSwitch
			v-model="localProvider.settings.sendIdTokenHint"
			wrapper-element="div">
			{{ t('user_oidc', 'Send ID token hint on logout') }}
		</NcCheckboxRadioSwitch>
		<p class="settings-hint">
			{{ t('user_oidc', 'Should the ID token be included as the id_token_hint GET parameter in the OpenID logout URL? Users are redirected to this URL after logging out of Nextcloud. Enabling this setting exposes the OIDC ID token to the user agent, which may not be necessary depending on the OIDC provider.') }}
		</p>
		<div class="provider-edit--footer">
			<NcButton @click="$emit('cancel-form')">
				{{ t('user_oidc', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('submit', localProvider)">
				<template #icon>
					<CheckIcon :size="20" />
				</template>
				{{ submitText }}
			</NcButton>
		</div>
	</form>
</template>

<script>
import AlertOutlineIcon from 'vue-material-design-icons/AlertOutline.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'

import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcButton from '@nextcloud/vue/components/NcButton'

export default {
	name: 'SettingsForm',
	components: {
		NcCheckboxRadioSwitch,
		NcButton,
		AlertOutlineIcon,
		CheckIcon,
		ChevronRightIcon,
		ChevronDownIcon,
	},
	props: {
		submitText: {
			type: String,
			default: t('user_oidc', 'Submit'),
		},
		update: {
			type: Boolean,
			default: false,
		},
		provider: {
			type: Object,
			required: true,
		},
	},
	emits: [
		'cancel-form',
		'submit',
	],
	data() {
		return {
			localProvider: null,
			maxIdentifierLength: 128,
			showProfileAttributes: false,
		}
	},
	computed: {
		identifierLength() {
			return this.localProvider.identifier.length
		},
	},
	created() {
		this.localProvider = this.provider
		this.identifierInitiallySet = !!this.localProvider.identifier
	},
	methods: {
		toggleProfileAttributes() {
			this.showProfileAttributes = !this.showProfileAttributes
		},
	},
}
</script>

<style scoped lang="scss">
.provider-edit {
	&--footer {
		display: flex;
		justify-content: right;
		padding: 8px 0;
		position: sticky;
		bottom: 0;
		background-color: var(--color-main-background);
		> * {
			margin: 0 4px;
		}
	}

	.settings-hint {
		display: flex;
		align-items: center;
		margin: 0;

		.icon {
			margin-right: 8px;
		}
	}

	.warning-hint {
		margin-left: 160px;
		background-color: var(--color-background-dark);
	}

	p {
		display: flex;
		align-items: center;
		label {
			width: 160px;
			display: inline-block;
		}

		input[type=text] {
			min-width: 200px;
			flex-grow: 1;
		}
		.italic-placeholder::placeholder {
			font-style: italic;
		}
	}
}
</style>
