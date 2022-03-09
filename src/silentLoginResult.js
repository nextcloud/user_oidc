const getParams = new URLSearchParams(window.location.search.substr(1))
const loggedIn = getParams.get('loggedIn')
console.debug('!!!!! loggedIn is ' + loggedIn)
parent.postMessage('NC Session Status: ' + loggedIn, 'https://parent.url')
