const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	'admin-settings': path.join(__dirname, 'src', 'main-settings.js'),
	timezone: path.join(__dirname, 'src', 'timezone.js'),
}

module.exports = webpackConfig
