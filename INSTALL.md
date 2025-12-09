<!--
  - SPDX-FileCopyrightText: 2024 Junovy and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Manual Installation Instructions for junovy_user_oidc

## Quick Start

1. **Package the app:**

    ```bash
    ./package.sh
    ```

2. **Upload to your server:**

    ```bash
    # Extract the package
    tar -xzf build/junovy_user_oidc-*.tar.gz

    # Upload to your Nextcloud server
    scp -r junovy_user_oidc-* user@your-server:/tmp/
    ```

3. **On your server:**

    ```bash
    # Move to apps directory (adjust path to your Nextcloud installation)
    sudo mv /tmp/junovy_user_oidc-* /var/www/nextcloud/apps/junovy_user_oidc

    # Set permissions
    sudo chown -R www-data:www-data /var/www/nextcloud/apps/junovy_user_oidc

    # Enable the app
    cd /var/www/nextcloud
    sudo -u www-data php occ app:enable junovy_user_oidc
    ```

## Detailed Installation Steps

### Prerequisites

-   Nextcloud server (version 29-33)
-   PHP with required extensions (curl, json, openssl, etc.)
-   Access to the Nextcloud installation directory
-   Command line access (for occ commands)
-   Web server user permissions (typically `www-data` or `nginx`)

### Step 1: Package the App

Run the packaging script from the repository root:

```bash
./package.sh
```

This will:

-   Build JavaScript files
-   Install PHP dependencies
-   Create a production-ready package in `build/user_oidc-{version}/`
-   Generate both `.tar.gz` and `.zip` archives

### Step 2: Transfer to Server

**Option A: Using SCP (recommended)**

```bash
scp build/user_oidc-*.tar.gz user@your-server:/tmp/
```

**Option B: Using SFTP**
Upload the `.tar.gz` or `.zip` file to your server via SFTP client.

**Option C: Direct copy (if server is local)**

```bash
cp -r build/user_oidc-* /path/to/nextcloud/apps/user_oidc
```

### Step 3: Extract and Install on Server

SSH into your server and run:

```bash
# Extract the package
cd /tmp
tar -xzf user_oidc-*.tar.gz

# Move to Nextcloud apps directory
# Adjust /var/www/nextcloud to your actual Nextcloud path
sudo mv user_oidc-* /var/www/nextcloud/apps/user_oidc

# Set correct ownership (adjust www-data to your web server user)
sudo chown -R www-data:www-data /var/www/nextcloud/apps/junovy_user_oidc

# Set directory permissions
sudo chmod -R 755 /var/www/nextcloud/apps/junovy_user_oidc
```

### Step 4: Enable the App

**Via command line (recommended):**

```bash
cd /var/www/nextcloud
sudo -u www-data php occ app:enable junovy_user_oidc
```

**Via web interface:**

1. Log in as administrator
2. Go to **Settings → Apps**
3. Search for "OpenID Connect user backend"
4. Click **Enable**

### Step 5: Verify Installation

Check that the app is enabled:

```bash
sudo -u www-data php occ app:list | grep junovy_user_oidc
```

You should see `junovy_user_oidc` in the enabled apps list.

### Step 6: Configure Your OIDC Provider

1. Go to **Settings → Administration → OpenID Connect**
2. Click **Add provider** or edit an existing provider
3. Fill in your OIDC provider details:

    - **Identifier**: A unique name for this provider
    - **Client ID**: Your OIDC client ID
    - **Client Secret**: Your OIDC client secret
    - **Discovery endpoint**: Your OIDC discovery URL

4. **For Kubernetes environments**, configure URL overrides:

    - **JWKS URI Override**: Internal service URL for JWKS
        - Example: `http://keycloak.keycloak.svc.cluster.local/realms/dds/protocol/openid-connect/certs`
    - **Token Endpoint Override**: Internal service URL for token endpoint
        - Example: `http://keycloak.keycloak.svc.cluster.local/realms/dds/protocol/openid-connect/token`
    - **UserInfo Endpoint Override**: Internal service URL for userinfo
        - Example: `http://keycloak.keycloak.svc.cluster.local/realms/dds/protocol/openid-connect/userinfo`

5. Configure additional settings as needed (auto-redirect, TLS verify, cache times, etc.)

## Configuration Options

### Per-Provider Settings (Admin UI)

All settings can be configured per-provider in the admin interface:

-   **URL Overrides**: Override discovery endpoint URLs
-   **Login Behavior**: Auto-redirect, hide password form, button text
-   **Authentication Options**: Password auth, WebDAV, ID token usage
-   **Cache Configuration**: Cache time settings
-   **Advanced Options**: Avatar updates, TLS verify, external storage, LDAP proxy

### Global Configuration (config.php)

You can set global defaults in `config.php` that apply to all providers:

```php
'junovy_user_oidc' => [
    // Auto-redirect to OIDC provider on login page
    'oidc_login_auto_redirect' => true,

    // Hide built-in password login form
    'oidc_login_hide_password_form' => true,

    // TLS certificate verification (false for self-signed/internal CA)
    'oidc_login_tls_verify' => false,

    // Cache time settings (0 = disable caching)
    'oidc_login_public_key_caching_time' => 0,
    'oidc_login_min_time_between_jwks_requests' => 0,
    'oidc_login_well_known_caching_time' => 0,

    // Other options...
    'oidc_login_button_text' => 'Log in with Junovy Cloud',
    'oidc_login_webdav_enabled' => true,
    'oidc_login_password_authentication' => true,
    'oidc_login_update_avatar' => true,
],
```

**Note:** Per-provider settings in the admin UI take precedence over global config.php settings.

## Troubleshooting

### App doesn't appear in Settings

-   Verify the app is enabled: `sudo -u www-data php occ app:enable junovy_user_oidc`
-   Check file permissions: `ls -la /var/www/nextcloud/apps/junovy_user_oidc`
-   Review Nextcloud logs: `tail -f /var/www/nextcloud/data/nextcloud.log`

### JavaScript not loading

-   Verify `js/` directory exists: `ls -la /var/www/nextcloud/apps/junovy_user_oidc/js/`
-   Clear browser cache and hard refresh (Ctrl+Shift+R / Cmd+Shift+R)
-   Check browser console for JavaScript errors
-   Verify web server can read the files

### Permission errors

```bash
# Fix ownership
sudo chown -R www-data:www-data /var/www/nextcloud/apps/junovy_user_oidc

# Fix permissions
sudo chmod -R 755 /var/www/nextcloud/apps/junovy_user_oidc
sudo find /var/www/nextcloud/apps/junovy_user_oidc -type f -exec chmod 644 {} \;
```

### OIDC connection issues

-   **Kubernetes environments**: Ensure URL overrides are configured with internal service URLs
-   **TLS errors**: Set `tls_verify` to `false` for self-signed certificates or internal CAs
-   **Discovery endpoint unreachable**: Check network connectivity from Nextcloud server
-   **Cache issues**: Set cache times to `0` to force immediate refresh

### Check app status

```bash
# List all apps
sudo -u www-data php occ app:list

# Check app info
sudo -u www-data php occ app:getpath junovy_user_oidc

# Check for errors
sudo -u www-data php occ app:check-code junovy_user_oidc
```

## Updating the App

1.  **Disable the app:**

    ```bash
    sudo -u www-data php occ app:disable junovy_user_oidc
    ```

2.  **Backup configuration (optional):**

    ```bash
    # Provider settings are stored in the database, but you can backup the app directory
    sudo cp -r /var/www/nextcloud/apps/junovy_user_oidc /var/www/nextcloud/apps/junovy_user_oidc.backup
    ```

3.  **Replace the app:**

        ```bash
        # Remove old version
        sudo rm -rf /var/www/nextcloud/apps/junovy_user_oidc

        # Extract new version
        cd /tmp

    tar -xzf junovy*user_oidc-*.tar.gz
    sudo mv junovy*user_oidc-* /var/www/nextcloud/apps/junovy_user_oidc

    ```

    ```

4.  **Set permissions:**

    ```bash
    sudo chown -R www-data:www-data /var/www/nextcloud/apps/junovy_user_oidc
    ```

5.  **Enable and upgrade:**
    ```bash
    cd /var/www/nextcloud
    sudo -u www-data php occ app:enable junovy_user_oidc
    sudo -u www-data php occ upgrade
    ```

## Uninstallation

To remove the app:

```bash
# Disable the app
sudo -u www-data php occ app:disable junovy_user_oidc

# Remove the app directory
sudo rm -rf /var/www/nextcloud/apps/junovy_user_oidc

# Clean up database (optional - removes all provider configurations)
# sudo -u www-data php occ app:remove junovy_user_oidc
```

## Support

-   **Documentation**: See README.md in the package
-   **Issues**: https://github.com/nextcloud/user_oidc/issues
-   **Nextcloud Logs**: `/var/www/nextcloud/data/nextcloud.log`
