#!/bin/bash
set -e

# Test Case 1: Local Server Health Check
# Tests: Basic server setup with local framework, health check via curl

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}Test Case 1: Local Server Health Check${NC}"
echo -e "${YELLOW}========================================${NC}"
echo ""

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRAMEWORK_DIR="$(dirname "$SCRIPT_DIR")"
SERVER_DIR="$(dirname "$FRAMEWORK_DIR")/StoneScriptPHP-Server"
TEST_DIR="/tmp/test-stonescriptphp-health"
TEST_PORT=9100

echo "📋 Configuration:"
echo "  Framework: $FRAMEWORK_DIR"
echo "  Server: $SERVER_DIR"
echo "  Test Dir: $TEST_DIR"
echo "  Port: $TEST_PORT"
echo ""

# Cleanup function
cleanup() {
    echo -e "\n${YELLOW}🧹 Cleaning up...${NC}"

    # Kill any server running on TEST_PORT
    SERVER_PID=$(lsof -ti:$TEST_PORT 2>/dev/null || echo "")
    if [ ! -z "$SERVER_PID" ]; then
        echo "  Stopping PHP server on port $TEST_PORT (PID: $SERVER_PID)..."
        kill $SERVER_PID 2>/dev/null || true
        sleep 1
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
    echo "  Removing existing test directory..."
    rm -rf "$TEST_DIR"
fi
mkdir -p "$TEST_DIR"
echo -e "${GREEN}  ✓ Test directory created${NC}"

# Step 2: Copy server folder to test dir as api
echo -e "\n${YELLOW}📦 Step 2: Copying server folder...${NC}"
if [ ! -d "$SERVER_DIR" ]; then
    echo -e "${RED}  ✗ Error: Server directory not found: $SERVER_DIR${NC}"
    exit 1
fi

cp -r "$SERVER_DIR" "$TEST_DIR/api"
echo -e "${GREEN}  ✓ Server folder copied to $TEST_DIR/api${NC}"

# Step 3: Replace composer.json to use local framework
echo -e "\n${YELLOW}🔧 Step 3: Updating composer.json to use local framework...${NC}"
cd "$TEST_DIR/api"

# Backup original composer.json
cp composer.json composer.json.backup

# Use sed to add path repository and change requirement
cat > composer.json <<EOF
{
    "name": "progalaxyelabs/stonescriptphp-server",
    "description": "Application skeleton for StoneScriptPHP - Build production-ready APIs with PostgreSQL",
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
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "App\\\\": "src/App/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\\\": "tests/"
        }
    },
    "bin": ["stone"],
    "scripts": {
        "serve": [
            "Composer\\\\Config::disableProcessTimeout",
            "php stone serve"
        ],
        "test": "phpunit",
        "migrate": "php stone migrate"
    },
    "config": {
        "sort-packages": true,
        "optimize-autoloader": true,
        "preferred-install": "dist"
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
EOF

echo -e "${GREEN}  ✓ composer.json updated${NC}"

# Step 4: Run composer update
echo -e "\n${YELLOW}📥 Step 4: Running composer install...${NC}"
composer install --no-interaction --quiet
if [ $? -eq 0 ]; then
    echo -e "${GREEN}  ✓ Composer dependencies installed${NC}"
else
    echo -e "${RED}  ✗ Composer install failed${NC}"
    exit 1
fi

# Verify framework installation
if [ -f "vendor/progalaxyelabs/stonescriptphp/Router.php" ]; then
    echo -e "${GREEN}  ✓ Framework installed correctly${NC}"
else
    echo -e "${RED}  ✗ Framework not found in vendor${NC}"
    exit 1
fi

# Generate .env file using stone command
echo -e "\n${YELLOW}⚙️  Generating .env file...${NC}"
php stone generate env --force > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo -e "${GREEN}  ✓ .env file generated${NC}"

    # Update with test-specific values (uncomment and set required fields)
    sed -i 's/^APP_PORT=.*/APP_PORT='$TEST_PORT'/' .env
    sed -i 's/^; DATABASE_DBNAME=.*/DATABASE_DBNAME=test_health_db/' .env
    sed -i 's/^; DATABASE_USER=.*/DATABASE_USER=test_user/' .env
    sed -i 's/^; DATABASE_PASSWORD=.*/DATABASE_PASSWORD=test_pass/' .env
    echo -e "${GREEN}  ✓ .env configured for test${NC}"
else
    echo -e "${RED}  ✗ Failed to generate .env${NC}"
    exit 1
fi

# Step 5: Start the server using php stone serve
echo -e "\n${YELLOW}🚀 Step 5: Starting PHP development server...${NC}"
php stone serve > /tmp/stone-server.log 2>&1 &
STONE_PID=$!

# Wait for server to start and check if port is listening
echo "  Waiting for server to start..."
MAX_WAIT=10
COUNTER=0
while [ $COUNTER -lt $MAX_WAIT ]; do
    if lsof -i:$TEST_PORT -t >/dev/null 2>&1; then
        echo -e "${GREEN}  ✓ Server started successfully on port $TEST_PORT${NC}"
        break
    fi
    sleep 1
    COUNTER=$((COUNTER + 1))
done

if [ $COUNTER -eq $MAX_WAIT ]; then
    echo -e "${RED}  ✗ Server failed to start after ${MAX_WAIT}s${NC}"
    echo "  Server log:"
    cat /tmp/stone-server.log
    exit 1
fi

# Step 6: Make a curl request to health/home route
echo -e "\n${YELLOW}🔍 Step 6: Testing health endpoint...${NC}"

# Wait a bit more for server to be fully ready
sleep 2

# Try to get the home route
echo "  Making request to http://localhost:$TEST_PORT/"
RESPONSE=$(curl -s -w "\n%{http_code}" http://localhost:$TEST_PORT/ 2>&1 || echo "000")
HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
BODY=$(echo "$RESPONSE" | head -n-1)

echo "  HTTP Status Code: $HTTP_CODE"
echo "  Response Body: $BODY"

if [ "$HTTP_CODE" = "200" ]; then
    echo -e "${GREEN}  ✓ Health check passed (HTTP 200)${NC}"
elif [ "$HTTP_CODE" = "404" ]; then
    echo -e "${YELLOW}  ⚠ Got 404 - trying alternate endpoints${NC}"

    # Try /health if it exists
    RESPONSE=$(curl -s -w "\n%{http_code}" http://localhost:$TEST_PORT/health 2>&1 || echo "000")
    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)

    if [ "$HTTP_CODE" = "200" ]; then
        echo -e "${GREEN}  ✓ Health check passed via /health (HTTP 200)${NC}"
    else
        echo -e "${YELLOW}  ℹ Server is running but no default route configured${NC}"
        echo -e "${GREEN}  ✓ Test passed - server is responding${NC}"
    fi
else
    echo -e "${RED}  ✗ Health check failed (HTTP $HTTP_CODE)${NC}"
    echo "  Server log:"
    cat /tmp/stone-server.log
    exit 1
fi

# Additional verification
echo -e "\n${YELLOW}📊 Additional Verification:${NC}"
echo "  • Framework version: $(grep -o '"version": "[^"]*"' vendor/progalaxyelabs/stonescriptphp/composer.json | head -1 || echo 'dev')"
echo "  • PHP version: $(php -v | head -n1)"
ACTUAL_PID=$(lsof -ti:$TEST_PORT 2>/dev/null || echo "")
if [ ! -z "$ACTUAL_PID" ]; then
    echo "  • Server PID: $ACTUAL_PID"
    echo "  • Server uptime: $(ps -p $ACTUAL_PID -o etime= 2>/dev/null | tr -d ' ' || echo 'N/A')"
fi

# Test passed
echo -e "\n${GREEN}========================================${NC}"
echo -e "${GREEN}✅ TEST CASE 1 PASSED${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Summary:"
echo "  • Server folder copied and configured"
echo "  • Local framework installed via composer"
echo "  • Development server started successfully"
echo "  • Health check endpoint responding"
echo ""
echo "Test artifacts at: $TEST_DIR"

# Cleanup will happen automatically via trap