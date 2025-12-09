#!/bin/bash
set -e

# Test Case 2: Dev Docker with Nginx Reverse Proxy
# Tests: Docker container with nginx proxy + php stone serve

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}Test Case 2: Dev Docker + Nginx${NC}"
echo -e "${YELLOW}========================================${NC}"
echo ""

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRAMEWORK_DIR="$(dirname "$SCRIPT_DIR")"
SERVER_DIR="$(dirname "$FRAMEWORK_DIR")/StoneScriptPHP-Server"
TEST_DIR="/tmp/test-stonescriptphp-dev-docker"
TEST_PORT=9151
INTERNAL_PORT=9100
IMAGE_NAME="stonescriptphp-dev-test"

echo "📋 Configuration:"
echo "  Framework: $FRAMEWORK_DIR"
echo "  Server: $SERVER_DIR"
echo "  Test Dir: $TEST_DIR"
echo "  External Port: $TEST_PORT"
echo "  Internal Port: $INTERNAL_PORT"
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
composer install --no-interaction --quiet
if [ $? -eq 0 ]; then
    echo -e "${GREEN}  ✓ Composer dependencies installed${NC}"
else
    echo -e "${RED}  ✗ Composer install failed${NC}"
    exit 1
fi

# Step 5: Create Dockerfile in server folder
echo -e "\n${YELLOW}🐳 Step 5: Creating Dockerfile...${NC}"

cat > Dockerfile <<'EOF'
FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    nginx \
    supervisor \
    curl

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql

# Copy application
WORKDIR /var/www/html
COPY . /var/www/html/

# Create nginx config
RUN mkdir -p /etc/nginx/http.d
RUN cat > /etc/nginx/http.d/default.conf <<'NGINX'
server {
    listen 80;
    server_name localhost;

    location / {
        proxy_pass http://127.0.0.1:9100;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
NGINX

# Create supervisor config to run both nginx and php stone serve
RUN cat > /etc/supervisor/conf.d/supervisord.conf <<'SUPERVISOR'
[supervisord]
nodaemon=true
user=root

[program:nginx]
command=/usr/sbin/nginx -g "daemon off;"
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:php-stone]
command=php /var/www/html/stone serve
directory=/var/www/html
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
SUPERVISOR

# Create minimal .env
RUN cat > .env <<'ENVFILE'
APP_NAME=StoneScriptPHP
APP_ENV=development
APP_PORT=9100

DATABASE_HOST=localhost
DATABASE_PORT=5432
DATABASE_DBNAME=test_db
DATABASE_USER=test_user
DATABASE_PASSWORD=test_pass

JWT_PRIVATE_KEY_PATH=./keys/jwt-private.pem
JWT_PUBLIC_KEY_PATH=./keys/jwt-public.pem
JWT_EXPIRY=3600

ALLOWED_ORIGINS=http://localhost:3000
ENVFILE

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
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
echo "  Waiting for services to start..."
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

# Wait a bit more for nginx and php server to be ready
sleep 3

# Make request
echo "  Making request to http://localhost:$TEST_PORT/"
RESPONSE=$(curl -s -w "\n%{http_code}" http://localhost:$TEST_PORT/ 2>&1 || echo "000")
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | head -n-1)

echo "  HTTP Status Code: $HTTP_CODE"
echo "  Response Body: $BODY"

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "404" ]; then
    echo -e "${GREEN}  ✓ Health check passed - server is responding${NC}"
else
    echo -e "${RED}  ✗ Health check failed (HTTP $HTTP_CODE)${NC}"
    echo "  Container logs:"
    docker logs $CONTAINER_ID
    exit 1
fi

# Additional verification
echo -e "\n${YELLOW}📊 Additional Verification:${NC}"
echo "  • Container status: $(docker ps --filter id=$CONTAINER_ID --format '{{.Status}}')"
echo "  • Nginx process: $(docker exec $CONTAINER_ID ps aux | grep nginx | grep -v grep | wc -l) process(es)"
echo "  • PHP process: $(docker exec $CONTAINER_ID ps aux | grep 'php.*stone' | grep -v grep | wc -l) process(es)"

# Test passed
echo -e "\n${GREEN}========================================${NC}"
echo -e "${GREEN}✅ TEST CASE 2 PASSED${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Summary:"
echo "  • Docker image built with nginx + php stone serve"
echo "  • Container running with supervisor"
echo "  • Nginx reverse proxy working"
echo "  • Health check endpoint responding"
echo "  • Port $TEST_PORT mapped successfully"
echo ""
echo "Test artifacts at: $TEST_DIR"

# Cleanup will happen automatically via trap
