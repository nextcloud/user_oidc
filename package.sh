#!/bin/bash

# SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Script to package user_oidc app for production deployment

set -e

APP_NAME="junovy_user_oidc"
# Extract version from info.xml (works on both GNU and BSD grep)
VERSION=$(grep -o '<version>[^<]*</version>' appinfo/info.xml | sed 's/<version>\(.*\)<\/version>/\1/' || echo "dev")
PACKAGE_NAME="${APP_NAME}-${VERSION}"
BUILD_DIR="build"
PACKAGE_DIR="${BUILD_DIR}/${PACKAGE_NAME}"

echo "=========================================="
echo "Packaging ${APP_NAME} v${VERSION}"
echo "=========================================="

# Clean previous builds
echo "Cleaning previous builds..."
rm -rf "${BUILD_DIR}"
mkdir -p "${PACKAGE_DIR}"

# Check if Node.js is available
if ! command -v node &> /dev/null; then
    echo "Error: Node.js is not installed. Please install Node.js to build the JavaScript files."
    exit 1
fi

# Check if npm is available
if ! command -v npm &> /dev/null; then
    echo "Error: npm is not installed. Please install npm to build the JavaScript files."
    exit 1
fi

# Check if PHP is available
if ! command -v php &> /dev/null; then
    echo "Error: PHP is not installed. Please install PHP to build the PHP dependencies."
    exit 1
fi

# Check if composer is available
if ! command -v composer &> /dev/null && [ ! -f "composer.phar" ]; then
    echo "Error: Composer is not installed. Please install Composer to build the PHP dependencies."
    exit 1
fi

# Build JavaScript files
echo "Building JavaScript files..."
npm ci
npm run build

if [ ! -d "js" ]; then
    echo "Error: JavaScript build failed. js/ directory not found."
    exit 1
fi

# Install PHP dependencies (production only, optimized)
echo "Installing PHP dependencies..."
if [ -f "composer.phar" ]; then
    php composer.phar install --no-dev -o
else
    composer install --no-dev -o
fi

# Copy necessary files and directories
echo "Copying files to package..."

# Core app files
cp -r appinfo "${PACKAGE_DIR}/"
cp -r lib "${PACKAGE_DIR}/"
cp -r src "${PACKAGE_DIR}/"
cp -r templates "${PACKAGE_DIR}/"
cp -r css "${PACKAGE_DIR}/"
cp -r img "${PACKAGE_DIR}/"
cp -r l10n "${PACKAGE_DIR}/"
cp -r LICENSES "${PACKAGE_DIR}/"

# Built JavaScript files
cp -r js "${PACKAGE_DIR}/"

# Vendor dependencies (PHP)
cp -r vendor "${PACKAGE_DIR}/"

# Mozart-scoped vendor dependencies
if [ -d "lib/Vendor" ]; then
    cp -r lib/Vendor "${PACKAGE_DIR}/lib/"
fi

# Autoload files
if [ -d "lib/autoload" ]; then
    cp -r lib/autoload "${PACKAGE_DIR}/lib/"
fi

# Configuration files
cp composer.json "${PACKAGE_DIR}/"
cp composer.lock "${PACKAGE_DIR}/"
cp COPYING "${PACKAGE_DIR}/" 2>/dev/null || true
cp README.md "${PACKAGE_DIR}/" 2>/dev/null || true

# Create installation instructions
cat > "${PACKAGE_DIR}/INSTALL.md" << 'EOF'
# Manual Installation Instructions for junovy_user_oidc

## Prerequisites

- Nextcloud server (version 29-33)
- PHP with required extensions
- Access to the Nextcloud installation directory
- Command line access (for occ commands)

## Installation Steps

### 1. Upload the Package

Upload the entire `junovy_user_oidc` directory to your Nextcloud `apps/` folder:

```bash
# Example: If your Nextcloud is installed at /var/www/nextcloud
cp -r junovy_user_oidc /var/www/nextcloud/apps/
```

### 2. Set Correct Permissions

Make sure the web server user owns the files:

```bash
# For Apache (typical user: www-data)
sudo chown -R www-data:www-data /var/www/nextcloud/apps/junovy_user_oidc

# For Nginx (typical user: www-data or nginx)
sudo chown -R www-data:www-data /var/www/nextcloud/apps/junovy_user_oidc
# OR
sudo chown -R nginx:nginx /var/www/nextcloud/apps/junovy_user_oidc
```

### 3. Enable the App

Enable the app using the Nextcloud occ command:

```bash
cd /var/www/nextcloud
sudo -u www-data php occ app:enable junovy_user_oidc
```

Or enable it via the web interface:
- Go to **Settings → Apps**
- Search for "Junovy OpenID Connect user backend"
- Click **Enable**

### 4. Verify Installation

Check that the app is enabled:

```bash
sudo -u www-data php occ app:list | grep junovy_user_oidc
```

You should see `junovy_user_oidc` in the list of enabled apps.

### 5. Configure the App

1. Go to **Settings → Administration → OpenID Connect**
2. Click **Add provider** or edit an existing provider
3. Configure your OIDC provider settings
4. **For Kubernetes environments**, use the URL Overrides section to set:
   - JWKS URI Override
   - Token Endpoint Override
   - UserInfo Endpoint Override
5. Configure any additional settings as needed

## Configuration Options

### URL Overrides (for Kubernetes)

If your OIDC provider returns external URLs that cannot be resolved from the Nextcloud backend, configure URL overrides:

- **JWKS URI Override**: Internal URL for JWKS endpoint
  - Example: `http://keycloak.keycloak.svc.cluster.local/realms/dds/protocol/openid-connect/certs`

- **Token Endpoint Override**: Internal URL for token endpoint
  - Example: `http://keycloak.keycloak.svc.cluster.local/realms/dds/protocol/openid-connect/token`

- **UserInfo Endpoint Override**: Internal URL for userinfo endpoint
  - Example: `http://keycloak.keycloak.svc.cluster.local/realms/dds/protocol/openid-connect/userinfo`

### Global Configuration (config.php)

You can also set global defaults in `config.php`:

```php
'junovy_user_oidc' => [
    'oidc_login_auto_redirect' => true,
    'oidc_login_hide_password_form' => true,
    'oidc_login_tls_verify' => false,  // For internal connections
    'oidc_login_public_key_caching_time' => 0,
    'oidc_login_min_time_between_jwks_requests' => 0,
    'oidc_login_well_known_caching_time' => 0,
    // ... other options
],
```

Per-provider settings in the admin UI will override global config.php settings.

## Troubleshooting

### App doesn't appear in Settings

- Check file permissions
- Verify the app is enabled: `sudo -u www-data php occ app:enable junovy_user_oidc`
- Check Nextcloud logs: `tail -f /var/www/nextcloud/data/nextcloud.log`

### JavaScript not loading

- Verify the `js/` directory exists and contains built files
- Clear browser cache
- Check browser console for errors

### Permission errors

- Ensure web server user owns all files: `sudo chown -R www-data:www-data /var/www/nextcloud/apps/junovy_user_oidc`
- Check directory permissions: `sudo chmod -R 755 /var/www/nextcloud/apps/junovy_user_oidc`

### OIDC connection issues

- Verify URL overrides are set correctly for Kubernetes environments
- Check TLS verification settings if using self-signed certificates
- Review Nextcloud logs for detailed error messages

## Updating the App

To update the app:

1. Disable the app: `sudo -u www-data php occ app:disable junovy_user_oidc`
2. Backup your configuration (if needed)
3. Replace the app directory with the new version
4. Set permissions: `sudo chown -R www-data:www-data /var/www/nextcloud/apps/junovy_user_oidc`
5. Enable the app: `sudo -u www-data php occ app:enable junovy_user_oidc`
6. Run migrations: `sudo -u www-data php occ upgrade`

## Support

For issues or questions:
- Check the README.md file
- Review Nextcloud logs
- Check the GitHub repository: https://github.com/nextcloud/user_oidc
EOF

# Create a tarball
echo "Creating tarball..."
cd "${BUILD_DIR}"
tar -czf "${PACKAGE_NAME}.tar.gz" "${PACKAGE_NAME}"
cd ..

# Create a zip file
echo "Creating zip archive..."
cd "${BUILD_DIR}"
zip -r "${PACKAGE_NAME}.zip" "${PACKAGE_NAME}" > /dev/null
cd ..

echo ""
echo "=========================================="
echo "Package created successfully!"
echo "=========================================="
echo ""
echo "Package location:"
echo "  - Directory: ${BUILD_DIR}/${PACKAGE_NAME}/"
echo "  - Tarball:   ${BUILD_DIR}/${PACKAGE_NAME}.tar.gz"
echo "  - Zip file:  ${BUILD_DIR}/${PACKAGE_NAME}.zip"
echo ""
echo "Installation instructions are in:"
echo "  ${BUILD_DIR}/${PACKAGE_NAME}/INSTALL.md"
echo ""
echo "To install on your Nextcloud server:"
echo "  1. Extract the package to your Nextcloud apps/ directory"
echo "  2. Set permissions: sudo chown -R www-data:www-data /path/to/nextcloud/apps/junovy_user_oidc"
echo "  3. Enable: sudo -u www-data php occ app:enable junovy_user_oidc"
echo ""
