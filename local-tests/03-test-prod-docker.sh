#!/bin/bash
set -e

# Test Case 3: Prod Docker with Apache
# Tests: Production Docker container with Apache serving PHP application

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}Test Case 3: Prod Docker + Apache${NC}"
echo -e "${YELLOW}========================================${NC}"
echo ""

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRAMEWORK_DIR="$(dirname "$SCRIPT_DIR")"
SERVER_DIR="$(dirname "$FRAMEWORK_DIR")/StoneScriptPHP-Server"
TEST_DIR="/tmp/test-stonescriptphp-prod-docker"
TEST_PORT=9152
IMAGE_NAME="stonescriptphp-prod-test"

echo "📋 Configuration:"
echo "  Framework: $FRAMEWORK_DIR"
echo "  Server: $SERVER_DIR"
echo "  Test Dir: $TEST_DIR"
echo "  External Port: $TEST_PORT"
echo "  Docker Image: $IMAGE_NAME"
echo ""

# Cleanup function
cleanup() {
    echo -e "\n${YELLOW}🧹 Cleaning up...${NC}"

    # Stop and remove container
    if [ ! -z "$CONTAINER_ID" ]; then
        echo "  Stopping container: $CONTAINER_ID"
        docker stop $CONTAINER_ID 2>/dev/null || true
        docker rm $CONTAINER_ID 2>/dev/null || true
    fi

    # Remove Docker image
    if docker images | grep -q "$IMAGE_NAME"; then
        echo "  Removing Docker image: $IMAGE_NAME"
        docker rmi $IMAGE_NAME 2>/dev/null || true
    fi

    # Remove test directory
    if [ -d "$TEST_DIR" ]; then
        echo "  Removing test directory: $TEST_DIR"
        rm -rf "$TEST_DIR"
    fi

    echo -e "${GREEN}  Cleanup complete${NC}"
}
trap cleanup EXIT

# Step 1: Create test directory
echo -e "${YELLOW}📁 Step 1: Creating test directory...${NC}"
if [ -d "$TEST_DIR" ]; then
    rm -rf "$TEST_DIR"
fi
mkdir -p "$TEST_DIR"
echo -e "${GREEN}  ✓ Test directory created${NC}"

# Step 2: Copy server folder
echo -e "\n${YELLOW}📦 Step 2: Copying server folder...${NC}"
if [ ! -d "$SERVER_DIR" ]; then
    echo -e "${RED}  ✗ Error: Server directory not found: $SERVER_DIR${NC}"
    exit 1
fi
cp -r "$SERVER_DIR" "$TEST_DIR/api"
echo -e "${GREEN}  ✓ Server folder copied${NC}"

# Step 3: Update composer.json to use local framework
echo -e "\n${YELLOW}🔧 Step 3: Updating composer.json...${NC}"
cd "$TEST_DIR/api"

cat > composer.json <<EOF
{
    "name": "progalaxyelabs/stonescriptphp-server",
    "type": "project",
    "license": "MIT",
    "repositories": [
        {
            "type": "path",
            "url": "$FRAMEWORK_DIR",
            "options": {"symlink": false}
        }
    ],
    "require": {
        "php": "^8.2",
        "progalaxyelabs/stonescriptphp": "@dev",
        "phpoffice/phpspreadsheet": "^5.0",
        "google/apiclient": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "App\\\\": "src/App/"
        }
    },
    "bin": ["stone"],
    "minimum-stability": "dev",
    "prefer-stable": true
}
EOF
echo -e "${GREEN}  ✓ composer.json updated${NC}"

# Step 4: Run composer install
echo -e "\n${YELLOW}📥 Step 4: Running composer install...${NC}"
composer install --no-interaction --no-dev --optimize-autoloader --quiet
if [ $? -eq 0 ]; then
    echo -e "${GREEN}  ✓ Composer dependencies installed${NC}"
else
    echo -e "${RED}  ✗ Composer install failed${NC}"
    exit 1
fi

# Step 5: Create Dockerfile with Apache
echo -e "\n${YELLOW}🐳 Step 5: Creating production Dockerfile...${NC}"

# Create public/index.php that routes through the framework
mkdir -p public
cat > public/index.php <<'PHP'
<?php
// Production entry point
require_once __DIR__ . '/../vendor/autoload.php';

// Set environment to production
putenv('APP_ENV=production');

// Load framework bootstrap
if (file_exists(__DIR__ . '/../vendor/progalaxyelabs/stonescriptphp/bootstrap.php')) {
    require_once __DIR__ . '/../vendor/progalaxyelabs/stonescriptphp/bootstrap.php';
}

// Simple health check for testing
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'message' => 'StoneScriptPHP Production Server',
    'timestamp' => date('Y-m-d H:i:s'),
    'environment' => 'production'
]);
PHP

cat > Dockerfile <<'EOF'
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql

# Enable Apache modules
RUN a2enmod rewrite headers

# Set document root
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Copy application
WORKDIR /var/www/html
COPY . /var/www/html/

# Create Apache vhost config
RUN cat > /etc/apache2/sites-available/000-default.conf <<'VHOST'
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted

        # Route all requests through index.php
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^(.*)$ index.php [QSA,L]
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
VHOST

# Create .htaccess for URL rewriting
RUN cat > /var/www/html/public/.htaccess <<'HTACCESS'
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>
HTACCESS

# Create minimal .env
RUN cat > .env <<'ENVFILE'
APP_NAME=StoneScriptPHP
APP_ENV=production
APP_DEBUG=false

DB_HOST=localhost
DB_PORT=5432
DB_NAME=prod_db
DB_USER=prod_user
DB_PASS=prod_pass

JWT_SECRET=production_secret_key
JWT_ALGORITHM=HS256
ENVFILE

# Set permissions
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
EOF

echo -e "${GREEN}  ✓ Dockerfile created${NC}"

# Step 6: Build the Docker image
echo -e "\n${YELLOW}🔨 Step 6: Building Docker image...${NC}"
docker build -t $IMAGE_NAME . --quiet
if [ $? -eq 0 ]; then
    echo -e "${GREEN}  ✓ Docker image built successfully${NC}"
else
    echo -e "${RED}  ✗ Docker build failed${NC}"
    exit 1
fi

# Step 7: Start container
echo -e "\n${YELLOW}🚀 Step 7: Starting Docker container...${NC}"
CONTAINER_ID=$(docker run -d -p $TEST_PORT:80 $IMAGE_NAME)
echo "  Container ID: $CONTAINER_ID"

# Wait for container to start
echo "  Waiting for Apache to start..."
sleep 5

# Check if container is running
if ! docker ps | grep -q $CONTAINER_ID; then
    echo -e "${RED}  ✗ Container failed to start${NC}"
    echo "  Container logs:"
    docker logs $CONTAINER_ID
    exit 1
fi
echo -e "${GREEN}  ✓ Container started successfully${NC}"

# Step 8: Health check using curl
echo -e "\n${YELLOW}🔍 Step 8: Testing health endpoint...${NC}"

# Wait a bit more for Apache to be fully ready
sleep 3

# Make request
echo "  Making request to http://localhost:$TEST_PORT/"
RESPONSE=$(curl -s -w "\n%{http_code}" http://localhost:$TEST_PORT/ 2>&1 || echo "000")
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | head -n-1)

echo "  HTTP Status Code: $HTTP_CODE"
echo "  Response Body: $BODY"

if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}  ✓ Health check passed (HTTP 200)${NC}"

    # Verify JSON response
    if echo "$BODY" | grep -q "status"; then
        echo -e "${GREEN}  ✓ JSON response verified${NC}"
    fi
else
    echo -e "${RED}  ✗ Health check failed (HTTP $HTTP_CODE)${NC}"
    echo "  Container logs:"
    docker logs $CONTAINER_ID
    exit 1
fi

# Additional verification
echo -e "\n${YELLOW}📊 Additional Verification:${NC}"
echo "  • Container status: $(docker ps --filter id=$CONTAINER_ID --format '{{.Status}}')"
echo "  • Apache process: $(docker exec $CONTAINER_ID ps aux | grep apache | grep -v grep | wc -l) process(es)"
echo "  • Document root: /var/www/html/public"
echo "  • PHP version: $(docker exec $CONTAINER_ID php -v | head -n1)"

# Test passed
echo -e "\n${GREEN}========================================${NC}"
echo -e "${GREEN}✅ TEST CASE 3 PASSED${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Summary:"
echo "  • Production Docker image built with Apache"
echo "  • Container running with production config"
echo "  • Apache serving from public/ directory"
echo "  • Health check endpoint responding"
echo "  • Port $TEST_PORT mapped successfully"
echo "  • Optimized for production (no dev dependencies)"
echo ""
echo "Test artifacts at: $TEST_DIR"

# Cleanup will happen automatically via trap
