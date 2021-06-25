<!--
  - @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
  -
  - @author Julius Härtl <jus@bitgrid.net>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<form class="provider-edit" @submit.prevent="$emit('submit', localProvider)">
		<p>
			<label for="oidc-identifier">{{ t('user_oidc', 'Identifier') }}</label>
			<input id="oidc-identifier"
				v-model="localProvider.identifier"
				type="text"
				:placeholder="t('user_oidc', 'Display name to identify the provider')"
				required>
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
		<p>
			<label for="oidc-discovery-endpoint">{{ t('user_oidc', 'Discovery endpoint') }}</label>
			<input id="oidc-discovery-endpoint"
				v-model="localProvider.discoveryEndpoint"
				type="text"
				required>
		</p>
		<h4>{{ t('user_oidc', 'Attribute mapping') }}</h4>
		<p>
			<label for="mapping-uid">{{ t('user_oidc', 'User ID mapping') }}</label>
			<input id="mapping-uid"
				v-model="localProvider.settings.mappingUid"
				type="text"
				placeholder="sub">
		</p>
		<p>
			<label for="mapping-displayName">{{ t('user_oidc', 'Display name mapping') }}</label>
			<input id="mapping-displayName"
				v-model="localProvider.settings.mappingDisplayName"
				type="text"
				placeholder="name">
		</p>
		<p>
			<label for="mapping-email">{{ t('user_oidc', 'Email mapping') }}</label>
			<input id="mapping-email"
				v-model="localProvider.settings.mappingEmail"
				type="text"
				placeholder="email">
		</p>
		<p>
			<label for="mapping-quota">{{ t('user_oidc', 'Quota mapping') }}</label>
			<input id="mapping-quota"
				v-model="localProvider.settings.mappingQuota"
				type="text"
				placeholder="quota">
		</p>
		<CheckboxRadioSwitch :checked.sync="localProvider.settings.uniqueUid" wrapper-element="div">
			{{ t('user_oidc', 'Use unique user id') }}
		</CheckboxRadioSwitch>
		<p class="settings-hint">
			{{ t('user_oidc', 'By default every user will get a unique userid that is a hashed value of the provider and user id. This can be turned off but uniqueness of users accross multiple user backends and providers is no longer preserved then.') }}
		</p>
		<input type="button" :value="t('user_oidc', 'Cancel')" @click="$emit('cancel')">
		<input type="submit" :value="submitText">
	</form>
</template>

<script>
import CheckboxRadioSwitch from '@nextcloud/vue/dist/Components/CheckboxRadioSwitch'

export default {
	name: 'SettingsForm',
	components: {
		CheckboxRadioSwitch,
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
	data() {
		return {
			localProvider: null,
		}
	},
	created() {
		this.localProvider = this.provider
	},
}
</script>

<style scoped>
p label {
	width: 160px;
	display: inline-block;
}

p input[type=text] {
	width: 100%;
	min-width: 200px;
	max-width: 400px;
}
</style>
