/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

import jstz from 'jstz'

console.debug('updating timezone and offset for OIDC user')

const url = generateUrl('/apps/user_oidc/config/timezone')
const params = {
	timezone: jstz.determine().name(),
	timezoneOffset: (-new Date().getTimezoneOffset() / 60),
}
axios.post(url, params).then(response => {
	console.debug('Successfully set OIDC user\'s timezone')
}).catch((error) => {
	console.error('Error while setting the OIDC user\'s timezone', error)
})
