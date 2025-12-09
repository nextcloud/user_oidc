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
				<NcCheckboxRadioSwitch
					v-model="id4meState"
					wrapper-element="div"
					@update:model-value="onId4MeChange">
					{{ t('user_oidc', 'Enable ID4me') }}
				</NcCheckboxRadioSwitch>
			</p>
			<p class="line">
				<NcCheckboxRadioSwitch
					v-model="storeLoginTokenState"
					wrapper-element="div"
					@update:model-value="onStoreLoginTokenChange">
					{{ t('user_oidc', 'Store login tokens') }}
				</NcCheckboxRadioSwitch>
				<NcButton variant="tertiary"
					:title="t('user_oidc', 'This is needed if you are using other apps that want to use user_oidc\'s token exchange or simply get the login token')">
					<template #icon>
						<HelpCircleOutlineIcon />
					</template>
				</NcButton>
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
				:no-close="true">
				<div class="providermodal__wrapper">
					<h3>{{ t('user_oidc', 'Register a new provider') }}</h3>
					<p class="settings-hint">
						{{ t('user_oidc', 'Configure your provider to redirect back to {url}', { url: redirectUrl }) }}
					</p>
					<SettingsForm :provider="newProvider" @submit="onSubmit" @cancel-form="showNewProvider=false" />
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
								<PencilOutlineIcon :size="20" />
							</template>
							{{ t('user_oidc', 'Update') }}
						</NcActionButton>
					</NcActions>
					<NcActions :style="customActionsStyle">
						<NcActionButton @click="onProviderDeleteClick(provider)">
							<template #icon>
								<TrashCanOutlineIcon :size="20" />
							</template>
							{{ t('user_oidc', 'Remove') }}
						</NcActionButton>
					</NcActions>
				</div>
			</div>

			<NcModal v-if="editProvider"
				size="large"
				:name="t('user_oidc', 'Update provider settings')"
				:no-close="true">
				<div class="providermodal__wrapper">
					<h3>{{ t('user_oidc', 'Update provider settings') }}</h3>
					<SettingsForm :provider="editProvider"
						:update="true"
						:submit-text="t('user_oidc', 'Update provider')"
						@submit="onUpdate"
						@cancel-form="editProvider = null" />
				</div>
			</NcModal>
			<NcDialog v-model:open="showDeletionConfirmation"
				:name="t('user_oidc', 'Confirm deletion')"
				:message="deletionConfirmationMessage">
				<template #actions>
					<NcButton
						@click="showDeletionConfirmation = false">
						{{ t('user_oidc', 'Cancel') }}
					</NcButton>
					<NcButton
						variant="error"
						@click="confirmDelete">
						<template #icon>
							<TrashCanOutlineIcon />
						</template>
						{{ t('user_oidc', 'Delete') }}
					</NcButton>
				</template>
			</NcDialog>
		</div>
		<div class="section">
			<h2>{{ t('user_oidc', 'Group Management') }}</h2>
			<p>
				{{ t('user_oidc', 'If groups were incorrectly synced as hashed values, you can resync them. This will remove users from hashed groups. Users will get proper groups on their next login.') }}
			</p>
			<p>
				<NcButton
					:disabled="resyncingGroups"
					@click="onResyncGroupsClick">
					<template #icon>
						<RefreshIcon v-if="!resyncingGroups" />
					</template>
					{{ resyncingGroups ? t('user_oidc', 'Resyncing...') : t('user_oidc', 'Resync Groups') }}
				</NcButton>
			</p>
		</div>
	</div>
</template>

<script>
import HelpCircleOutlineIcon from 'vue-material-design-icons/HelpCircleOutline.vue'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import TrashCanOutlineIcon from 'vue-material-design-icons/TrashCanOutline.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
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
		NcButton,
		PencilOutlineIcon,
		TrashCanOutlineIcon,
		NcDialog,
		PlusIcon,
		HelpCircleOutlineIcon,
		RefreshIcon,
	},
	props: {
		initialId4MeState: {
			type: Boolean,
			required: true,
		},
		initialStoreLoginTokenState: {
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
			storeLoginTokenState: this.initialStoreLoginTokenState,
			loadingStoreLoginToken: false,
			providers: this.initialProviders,
			newProvider: {
				identifier: '',
				clientId: '',
				clientSecret: '',
				discoveryEndpoint: '',
				endSessionEndpoint: '',
				postLogoutUri: '',
				settings: {
					uniqueUid: true,
					checkBearer: false,
					bearerProvisioning: false,
					providerBasedId: false,
					groupProvisioning: false,
					sendIdTokenHint: true,
					teamsProvisioning: false,
					mappingOrganizations: '',
					teamsWhitelistRegex: '',
					protectedGroups: 'users,admin',
				},
			},
			showNewProvider: false,
			editProvider: null,
			customActionsStyle: {
				'--color-background-hover': 'var(--color-background-darker)',
			},
			redirectUri: window.location.protocol + '//' + window.location.host + generateUrl('/apps/junovy_user_oidc/code'),
			showDeletionConfirmation: false,
			providerToDelete: null,
			resyncingGroups: false,
		}
	},
	computed: {
		deletionConfirmationMessage() {
			if (this.providerToDelete) {
				return t('user_oidc', 'Are you sure you want to delete the provider "{providerName}"?', { providerName: this.providerToDelete.identifier })
			}
			return ''
		},
	},
	methods: {
		async onId4MeChange(newValue) {
			logger.info('ID4me state changed', { enabled: newValue })

			this.loadingId4Me = true
			try {
				await confirmPassword()
				const url = generateUrl('/apps/junovy_user_oidc/provider/id4me')

				await axios.post(url, {
					enabled: newValue,
				})
			} catch (error) {
				logger.error('Could not save ID4me state: ' + error.message, { error })
				showError(t('user_oidc', 'Could not save ID4me state: {msg}', { msg: error.message }))
			} finally {
				this.loadingId4Me = false
			}
		},
		async onStoreLoginTokenChange(newValue) {
			logger.info('Store login token state changed', { enabled: newValue })

			this.loadingStoreLoginToken = true
			try {
				await confirmPassword()
				const url = generateUrl('/apps/junovy_user_oidc/admin-config')

				await axios.post(url, {
					values: {
						store_login_token: newValue,
					},
				})
			} catch (error) {
				logger.error('Could not save storeLoginToken state: ' + error.message, { error })
				showError(t('user_oidc', 'Could not save storeLoginToken state: {msg}', { msg: error.message }))
			} finally {
				this.loadingStoreLoginToken = false
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

			const url = generateUrl(`/apps/junovy_user_oidc/provider/${provider.id}`)
			try {
				await axios.put(url, provider)
				this.editProvider = null
				const index = this.providers.findIndex((p) => p.id === provider.id)
				this.providers[index] = provider
			} catch (error) {
				logger.error('Could not update the provider: ' + error.message, { error })
				showError(t('user_oidc', 'Could not update the provider:') + ' ' + (error.response?.data?.message ?? error.message))
			}
		},
		onProviderDeleteClick(provider) {
			this.providerToDelete = provider
			this.showDeletionConfirmation = true
		},
		confirmDelete() {
			this.deleteProvider(this.providerToDelete)
			this.showDeletionConfirmation = false
		},
		async deleteProvider(provider) {
			await confirmPassword()
			logger.info('Remove oidc provider', { provider })

			const url = generateUrl(`/apps/junovy_user_oidc/provider/${provider.id}`)
			try {
				await axios.delete(url)

				this.providers = this.providers.filter(p => p.id !== provider.id)
			} catch (error) {
				logger.error('Could not remove a provider: ' + error.message, { error })
				showError(t('user_oidc', 'Could not remove provider: {msg}', { msg: error.message }))
			}
			this.providerToDelete = null
		},
		async onSubmit() {
			await confirmPassword()
			logger.info('Add new oidc provider', { data: this.newProvider })

			const url = generateUrl('/apps/junovy_user_oidc/provider')
			try {
				const response = await axios.post(url, this.newProvider)

				this.providers.push(response.data)

				this.newProvider.identifier = ''
				this.newProvider.clientId = ''
				this.newProvider.clientSecret = ''
				this.newProvider.discoveryEndpoint = ''
				this.newProvider.endSessionEndpoint = ''
				this.newProvider.postLogoutUri = ''
				this.showNewProvider = false
			} catch (error) {
				logger.error('Could not register a provider: ' + error.message, { error })
				showError(t('user_oidc', 'Could not register provider:') + ' ' + (error.response?.data?.message ?? error.message))
			}
		},
		getBackchannelUrl(provider) {
			return window.location.protocol + '//' + window.location.host
				+ generateUrl('/apps/junovy_user_oidc/backchannel-logout/{identifier}', { identifier: provider.identifier })
		},
		async onResyncGroupsClick() {
			await confirmPassword()
			logger.info('Resyncing groups')

			this.resyncingGroups = true
			try {
				const url = generateUrl('/apps/junovy_user_oidc/resync-groups')
				const response = await axios.post(url)

				if (response.data.success) {
					const stats = response.data.stats
					const message = t('user_oidc', 'Groups resynced successfully. Found {count} hashed groups, removed {users} users. Users will get proper groups on their next login.', {
						count: stats.hashed_groups_found,
						users: stats.users_removed,
					})
					showSuccess(message)
				} else {
					showError(t('user_oidc', 'Failed to resync groups: {msg}', { msg: response.data.message || 'Unknown error' }))
				}
			} catch (error) {
				logger.error('Could not resync groups: ' + error.message, { error })
				showError(t('user_oidc', 'Could not resync groups: {msg}', { msg: error.response?.data?.message || error.message }))
			} finally {
				this.resyncingGroups = false
			}
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

.line {
	display: flex;
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
