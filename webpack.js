const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	'admin-settings': path.join(__dirname, 'src', 'main-settings.js'),
	'silentLoginResult': path.join(__dirname, 'src', 'silentLoginResult.js')
}

module.exports = webpackConfig
