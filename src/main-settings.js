/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'
import '@nextcloud/dialogs/style.css'
import Vue from 'vue'

import App from './components/AdminSettings.vue'

Vue.prototype.t = t
Vue.prototype.n = n
Vue.prototype.OC = OC
Vue.prototype.OCA = OCA

const View = Vue.extend(App)
new View({
	propsData: {
		initialId4MeState: loadState('user_oidc', 'id4meState'),
		initialProviders: loadState('user_oidc', 'providers'),
		redirectUrl: loadState('user_oidc', 'redirectUrl'),
	},
}).$mount('#user-oidc-settings')
