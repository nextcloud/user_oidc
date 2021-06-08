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
	<div>
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
		</div>
		<div class="section">
			<h2>
				{{ t('user_oidc', 'Registered Providers') }}
				<Actions>
					<ActionButton icon="icon-add" @click="showNewProvider=true">
						{{ t('user_oidc', 'Register new provider') }}
					</ActionButton>
				</Actions>
			</h2>

			<div v-if="showNewProvider">
				<h3>{{ t('user_oids', 'Register a new provider') }}</h3>
				<p class="settings-hint">
					{{ t('user_oidc', 'Configure your provider to redirect back to {url}', {url: redirectUrl}) }}
				</p>
				<form @submit.prevent="onSubmit">
					<p>
						<label for="oidc-new-identifier">{{ t('user_oidc', 'Identifier') }}</label>
						<input id="oidc-new-identifier"
							v-model="newProvider.identifier"
							type="text"
							required>
					</p>
					<p>
						<label for="oidc-new-client-id">{{ t('user_oidc', 'Client ID') }}</label>
						<input id="oidc-new-client-id"
							v-model="newProvider.clientId"
							type="text"
							required>
					</p>
					<p>
						<label for="oidc-new-client-secret">{{ t('user_oidc', 'Client secret') }}</label>
						<input id="oidc-new-client-secret"
							v-model="newProvider.clientSecret"
							type="text"
							autocomplete="off"
							required>
					</p>
					<p>
						<label for="oidc-new-discovery-endpoint">{{ t('user_oidc', 'Discovery endpoint') }}</label>
						<input id="oidc-new-discovery-endpoint"
							v-model="newProvider.discoveryEndpoint"
							type="text"
							required>
					</p>
					<input type="button" :value="t('user_oidc', 'Cancel')" @click="showNewProvider=false">
					<input type="submit" :value="t('user_oidc', 'Register new provider')">
				</form>
			</div>

			<div class="oidcproviders">
				<p v-if="providers.length === 0">
					{{ t('user_oidc', 'No providers registered.') }}
				</p>
				<div v-for="provider in providers"
					v-else
					:key="provider.id"
					class="oidcproviders__provider">
					<div class="oidcproviders__details">
						<b>{{ provider.identifier }}</b><br>
						{{ t('user_oidc', 'Client ID') }}: {{ provider.clientId }}<br>
						{{ t('user_oidc', 'Discovery endpoint') }}: {{ provider.discoveryEndpoint }}
					</div>
					<Actions>
						<ActionButton icon="icon-rename" @click="updateProvider(provider)">
							{{ t('user_oidc', 'Update') }}
						</ActionButton>
					</Actions>
					<Actions>
						<ActionButton icon="icon-delete" @click="onRemove(provider)">
							{{ t('user_oidc', 'Remove') }}
						</ActionButton>
					</Actions>
				</div>
			</div>

			<form v-if="editProvider" @submit.prevent="onUpdate">
				<p>
					<label for="oidc-identifier">{{ t('user_oidc', 'Identifier') }}</label>
					<input id="oidc-identifier"
						v-model="editProvider.identifier"
						type="text"
						required>
				</p>
				<p>
					<label for="oidc-client-id">{{ t('user_oidc', 'Client ID') }}</label>
					<input id="oidc-client-id"
						v-model="editProvider.clientId"
						type="text"
						required>
				</p>
				<p>
					<label for="oidc-client-secret">{{ t('user_oidc', 'Client secret') }}</label>
					<input id="oidc-client-secret"
						v-model="editProvider.clientSecret"
						:placeholder="t('user_oidc', 'Leave empty to keep existing')"
						type="text"
						autocomplete="off">
				</p>
				<p>
					<label for="oidc-discovery-endpoint">{{ t('user_oidc', 'Discovery endpoint') }}</label>
					<input id="oidc-discovery-endpoint"
						v-model="editProvider.discoveryEndpoint"
						type="text"
						required>
				</p>
				<input type="button" :value="t('user_oidc', 'Cancel')" @click="updateProviderCancel">
				<input type="submit" :value="t('user_oidc', 'Update')">
			</form>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import Actions from '@nextcloud/vue/dist/Components/Actions'
import ActionButton from '@nextcloud/vue/dist/Components/ActionButton'

import logger from '../logger'

export default {
	name: 'AdminSettings',
	components: {
		Actions,
		ActionButton,
	},
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
			showNewProvider: false,
			editProvider: null,
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
		updateProvider(provider) {
			this.editProvider = provider
		},
		updateProviderCancel(provider) {
			this.editProvider = null
		},
		async onUpdate() {
			logger.info('Update oidc provider', { data: this.editProvider })

			const url = generateUrl(`/apps/user_oidc/provider/${this.editProvider.id}`)
			try {
				await axios.put(url, this.editProvider)
				this.editProvider = null
			} catch (error) {
				logger.error('Could not update the provider: ' + error.message, { error })
				showError(t('user_oidc', 'Could not update the provider: ' + error.message))
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
<style lang="scss" scoped>
p label {
	width: 130px;
	display: inline-block;
}

p input[type=text] {
	width: 100%;
	min-width: 200px;
	max-width: 400px;
}

h2 .action-item {
	vertical-align: middle;
	margin-top: -2px;
}

h3 {
	font-weight: bold;
	padding-bottom: 12px;
}

.oidcproviders {
	margin-top: 20px;
	border-top: 1px solid var(--color-border);
	max-width: 900px;
}

.oidcproviders__provider {
	border-bottom: 1px solid var(--color-border);
	padding: 10px;
	display: flex;

	&:hover {
		background-color: var(--color-background-hover);
	}
	.oidcproviders__details {
		flex-grow: 1;
	}
}
</style>
