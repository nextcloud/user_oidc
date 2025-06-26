/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'
import '@nextcloud/dialogs/style.css'
import { createApp } from 'vue'

import App from './components/AdminSettings.vue'

const app = createApp(App, {
	initialId4MeState: loadState('user_oidc', 'id4meState'),
	initialStoreLoginTokenState: loadState('user_oidc', 'storeLoginTokenState'),
	initialProviders: loadState('user_oidc', 'providers'),
	redirectUrl: loadState('user_oidc', 'redirectUrl'),
})
app.mixin({ methods: { t, n } })
app.mount('#user-oidc-settings')
