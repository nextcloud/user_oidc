<!--
  - @copyright 2020 Christoph Wurst <christoph@winzerhof-wurst.at>
  -
  - @author 2020 Christoph Wurst <christoph@winzerhof-wurst.at>
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
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program.  If not, see <http://www.gnu.org/licenses/>.
  -->

<template>
	<div class="section">
		<h2>{{ t('user_oidc', 'OpenID Connect') }}</h2>
		<p>
			{{ t('user_oidc', 'Allows users to authenticate via OpenID Connect providers.') }}
		</p>
		<p>
			<input id="user-oidc-id4me"
				v-model="id4meState"
				type="checkbox"
				class="checkbox"
				:disabled="loadingId4Me"
				@change="onId4MeChange">
			<label for="user-oidc-id4me">{{ t('user_oidc', 'Enable ID4me') }}</label>
		</p>

		<h3>{{ t('user_oidc', 'Registered Providers') }}</h3>
		<p v-if="providers.length === 0">
			{{ t('user_oidc', 'No providers registered.') }}
		</p>
		<div v-for="provider in providers" v-else :key="provider.id">
			<b>{{ provider.identifier }}</b><br>
			{{ t('user_oidc', 'Client ID') }}: {{ provider.clientId }}<br>
			{{ t('user_oidc', 'Discovery endpoint') }}: {{ provider.discoveryEndpoint }}<br>
			<input type="button" :value="t('user_oidc', 'Remove')" @click="onRemove(provider)">
		</div>

		<h3>{{ t('user_oids', 'Register') }}</h3>
		<span>
			{{ t('user_oidc', 'Configure your provider to redirect back to {url}', {url: redirectUrl}) }}
		</span>
		<form @submit.prevent="onSubmit">
			<label for="oidc-identifier">{{ t('user_oidc', 'Identifier') }}</label>
			<input id="oidc-identifier" v-model="newProvider.identifier" type="text">
			<label for="oidc-client-id">{{ t('user_oidc', 'Client ID') }}</label>
			<input id="oidc-client-id" v-model="newProvider.clientId" type="text">
			<label for="oidc-client-secret">{{ t('user_oidc', 'Client secret') }}</label>
			<input id="oidc-client-secret"
				v-model="newProvider.clientSecret"
				type="text"
				autocomplete="off">
			<label for="oidc-discovery-endpoint">{{ t('user_oidc', 'Discovery endpoint') }}</label>
			<input id="oidc-discovery-endpoint" v-model="newProvider.discoveryEndpoint" type="text">

			<input type="submit" :value="t('user_oidc', 'Register')">
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

import logger from '../logger'

export default {
	name: 'AdminSettings',
	props: {
		initialId4MeState: {
			type: Boolean,
			required: true,
		},
		initialProviders: {
			type: Array,
			required: true,
		},
		redirectUrl: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			id4meState: this.initialId4MeState,
			loadingId4Me: false,
			providers: this.initialProviders,
			newProvider: {
				identifier: '',
				clientId: '',
				clientSecret: '',
				discoveryEndpoint: '',
			},
		}
	},
	methods: {
		async onId4MeChange() {
			logger.info('ID4me state changed', { enabled: this.id4meState })

			this.loadingId4Me = true
			try {
				const url = generateUrl('/apps/user_oidc/provider/id4me')

				await axios.post(url, {
					enabled: this.id4meState,
				}
				)
			} catch (error) {
				logger.error('Could not save ID4me state: ' + error.message, { error })
				showError(t('user_oidc', 'Could not save ID4me state: ' + error.message))
			} finally {
				this.loadingId4Me = false
			}
		},
		async onRemove(provider) {
			logger.info('Remove oidc provider', { provider })

			const url = generateUrl(`/apps/user_oidc/provider/${provider.id}`)
			try {
				await axios.delete(url)

				this.providers = this.providers.filter(p => p.id !== provider.id)
			} catch (error) {
				logger.error('Could not remove a provider: ' + error.message, { error })
				showError(t('user_oidc', 'Could not remove provider: ' + error.message))
			}
		},
		async onSubmit() {
			logger.info('Add new oidc provider', { data: this.newProvider })

			const url = generateUrl('/apps/user_oidc/provider')
			try {
				const response = await axios.post(url, this.newProvider)

				this.providers.push(response.data)

				this.newProvider.identifier = ''
				this.newProvider.clientId = ''
				this.newProvider.clientSecret = ''
				this.newProvider.discoveryEndpoint = ''
			} catch (error) {
				logger.error('Could not register a provider: ' + error.message, { error })
				showError(t('user_oidc', 'Could not register provider: ' + error.message))
			}
		},
	},
}
</script>
