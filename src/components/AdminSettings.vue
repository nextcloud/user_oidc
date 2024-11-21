<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div>
		<div class="section">
			<h2>{{ t('user_oidc', 'OpenID Connect') }}</h2>
			<p>
				{{ t('user_oidc', 'Allows users to authenticate via OpenID Connect providers.') }}
			</p>
			<p>
				<NcCheckboxRadioSwitch :checked.sync="id4meState"
					wrapper-element="div"
					@update:checked="onId4MeChange">
					{{ t('user_oidc', 'Enable ID4me') }}
				</NcCheckboxRadioSwitch>
			</p>
		</div>
		<div class="section">
			<h2>
				{{ t('user_oidc', 'Registered Providers') }}
				<NcActions>
					<NcActionButton @click="showNewProvider=true">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('user_oidc', 'Register new provider') }}
					</NcActionButton>
				</NcActions>
			</h2>

			<NcModal v-if="showNewProvider"
				size="large"
				:name="t('user_oidc', 'Register a new provider')"
				:can-close="false">
				<div class="providermodal__wrapper">
					<h3>{{ t('user_oidc', 'Register a new provider') }}</h3>
					<p class="settings-hint">
						{{ t('user_oidc', 'Configure your provider to redirect back to {url}', { url: redirectUrl }) }}
					</p>
					<SettingsForm :provider="newProvider" @submit="onSubmit" @cancel="showNewProvider=false" />
				</div>
			</NcModal>

			<div class="oidcproviders">
				<p v-if="providers.length === 0">
					{{ t('user_oidc', 'No providers registered.') }}
				</p>
				<div v-for="provider in providers"
					v-else
					:key="provider.id"
					class="oidcproviders__provider">
					<div class="oidcproviders__details">
						<h3>{{ provider.identifier }}</h3>
						<label>{{ t('user_oidc', 'Client ID') }}</label>
						<span>{{ provider.clientId }}</span>
						<label>{{ t('user_oidc', 'Discovery endpoint') }}</label>
						<span>{{ provider.discoveryEndpoint }}</span>
						<label>{{ t('user_oidc', 'Backchannel Logout URL') }}</label>
						<span>{{ getBackchannelUrl(provider) }}</span>
						<label>{{ t('user_oidc', 'Redirect URI (to be authorized in the provider client configuration)') }}</label>
						<span>{{ redirectUri }}</span>
					</div>
					<NcActions :style="customActionsStyle">
						<NcActionButton @click="updateProvider(provider)">
							<template #icon>
								<PencilIcon :size="20" />
							</template>
							{{ t('user_oidc', 'Update') }}
						</NcActionButton>
					</NcActions>
					<NcActions :style="customActionsStyle">
						<NcActionButton @click="onRemove(provider)">
							<template #icon>
								<DeleteIcon :size="20" />
							</template>
							{{ t('user_oidc', 'Remove') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<NcModal v-if="editProvider"
				size="large"
				:name="t('user_oidc', 'Update provider settings')"
				:can-close="false">
				<div class="providermodal__wrapper">
					<h3>{{ t('user_oidc', 'Update provider settings') }}</h3>
					<SettingsForm :provider="editProvider"
						:update="true"
						:submit-text="t('user_oidc', 'Update provider')"
						@submit="onUpdate"
						@cancel="editProvider=null" />
				</div>
			</NcModal>
		</div>
	</div>
</template>

<script>
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import { confirmPassword } from '@nextcloud/password-confirmation'

import logger from '../logger.js'
import SettingsForm from './SettingsForm.vue'

export default {
	name: 'AdminSettings',
	components: {
		SettingsForm,
		NcActions,
		NcActionButton,
		NcModal,
		NcCheckboxRadioSwitch,
		PencilIcon,
		DeleteIcon,
		PlusIcon,
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
				endSessionEndpoint: '',
				settings: {
					uniqueUid: true,
					checkBearer: false,
					bearerProvisioning: false,
					providerBasedId: false,
					groupProvisioning: false,
					sendIdTokenHint: true,
				},
			},
			showNewProvider: false,
			editProvider: null,
			customActionsStyle: {
				'--color-background-hover': 'var(--color-background-darker)',
			},
			redirectUri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/user_oidc/code'),
		}
	},
	methods: {
		async onId4MeChange(newValue) {
			logger.info('ID4me state changed', { enabled: newValue })

			this.loadingId4Me = true
			try {
				await confirmPassword()
				const url = generateUrl('/apps/user_oidc/provider/id4me')

				await axios.post(url, {
					enabled: newValue,
				})
			} catch (error) {
				logger.error('Could not save ID4me state: ' + error.message, { error })
				showError(t('user_oidc', 'Could not save ID4me state: ' + error.message))
			} finally {
				this.loadingId4Me = false
			}
		},
		updateProvider(provider) {
			this.editProvider = { ...provider }
		},
		updateProviderCancel() {
			this.editProvider = null
		},
		async onUpdate(provider) {
			await confirmPassword()
			logger.info('Update oidc provider', { data: provider })

			const url = generateUrl(`/apps/user_oidc/provider/${provider.id}`)
			try {
				await axios.put(url, provider)
				this.editProvider = null
				const index = this.providers.findIndex((p) => p.id === provider.id)
				this.$set(this.providers, index, provider)
			} catch (error) {
				logger.error('Could not update the provider: ' + error.message, { error })
				showError(t('user_oidc', 'Could not update the provider:') + ' ' + (error.response?.data?.message ?? error.message))
			}
		},
		async onRemove(provider) {
			await confirmPassword()
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
			await confirmPassword()
			logger.info('Add new oidc provider', { data: this.newProvider })

			const url = generateUrl('/apps/user_oidc/provider')
			try {
				const response = await axios.post(url, this.newProvider)

				this.providers.push(response.data)

				this.newProvider.identifier = ''
				this.newProvider.clientId = ''
				this.newProvider.clientSecret = ''
				this.newProvider.discoveryEndpoint = ''
				this.newProvider.endSessionEndpoint = ''
				this.showNewProvider = false
			} catch (error) {
				logger.error('Could not register a provider: ' + error.message, { error })
				showError(t('user_oidc', 'Could not register provider:') + ' ' + (error.response?.data?.message ?? error.message))
			}
		},
		getBackchannelUrl(provider) {
			return window.location.protocol + '//' + window.location.host
				+ generateUrl('/apps/user_oidc/backchannel-logout/{identifier}', { identifier: provider.identifier })
		},
	},
}
</script>
<style lang="scss" scoped>
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
	align-items: start;

	&:hover {
		background-color: var(--color-background-hover);
	}
	.oidcproviders__details {
		flex-grow: 1;
		display: flex;
		flex-direction: column;
		h3 {
			text-align: center;
		}
		label {
			font-weight: bold;
		}
	}
}

.providermodal__wrapper {
	margin: 20px;
}
</style>
