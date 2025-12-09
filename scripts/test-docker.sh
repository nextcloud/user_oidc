#!/bin/bash
# SPDX-FileCopyrightText: 2024 Junovy and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Run PHPUnit tests in a Docker container matching the CI environment
#
# Usage:
#   ./scripts/test-docker.sh          # Run all unit tests
#   ./scripts/test-docker.sh --filter testName  # Run specific test
#

set -e

# Configuration
PHP_VERSION="${PHP_VERSION:-8.1}"
NC_VERSION="${NC_VERSION:-stable32}"
APP_NAME="junovy_user_oidc"

echo "=========================================="
echo "Running tests with PHP ${PHP_VERSION} + Nextcloud ${NC_VERSION}"
echo "=========================================="

# Build a temporary Dockerfile
DOCKERFILE=$(mktemp)
cat > "$DOCKERFILE" << 'EOF'
FROM php:8.1-cli

# Install dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libicu-dev \
    libpq-dev \
    && docker-php-ext-install zip gd intl pdo_mysql pdo_pgsql

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
EOF

# Build the image
echo "Building Docker image..."
docker build -t nc-test-runner -f "$DOCKERFILE" . > /dev/null 2>&1
rm "$DOCKERFILE"

# Run tests
echo "Running PHPUnit tests..."
docker run --rm \
    -v "$(pwd)":/app \
    -w /app \
    nc-test-runner \
    bash -c "
        composer install --no-interaction --quiet 2>/dev/null
        ./vendor/bin/phpunit -c tests/phpunit.unit.xml $*
    "

echo ""
echo "=========================================="
echo "Tests completed!"
echo "=========================================="
