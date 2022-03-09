import { loadState } from '@nextcloud/initial-state'

const parentUrl = loadState('user_oidc', 'silent_login_parent_url')
console.debug('!!!!!!!!!! parent ' + parentUrl)

const getParams = new URLSearchParams(window.location.search.substr(1))
const loggedIn = getParams.get('loggedIn')
console.debug('!!!!! loggedIn is ' + loggedIn)
parent.postMessage('NC Session Status: ' + loggedIn, parentUrl)
