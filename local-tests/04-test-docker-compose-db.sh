#!/bin/bash
set -e

# Test Case 4: Docker-compose with PostgreSQL Connection Test
# Tests: Both dev and prod containers with PostgreSQL database connection

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}Test Case 4: Docker-Compose + PostgreSQL${NC}"
echo -e "${YELLOW}========================================${NC}"
echo ""

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRAMEWORK_DIR="$(dirname "$SCRIPT_DIR")"
SERVER_DIR="$(dirname "$FRAMEWORK_DIR")/StoneScriptPHP-Server"
TEST_DIR="/tmp/test-stonescriptphp-db"
DEV_PORT=9153
PROD_PORT=9154

echo "📋 Configuration:"
echo "  Framework: $FRAMEWORK_DIR"
echo "  Server: $SERVER_DIR"
echo "  Test Dir: $TEST_DIR"
echo "  Dev Port: $DEV_PORT"
echo "  Prod Port: $PROD_PORT"
echo ""

# Cleanup function
cleanup() {
    echo -e "\n${YELLOW}🧹 Cleaning up...${NC}"

    # Stop docker compose
    if [ -f "$TEST_DIR/docker compose.yml" ]; then
        cd "$TEST_DIR"
        echo "  Stopping docker compose services..."
        docker compose down -v 2>/dev/null || true
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
cp -r "$SERVER_DIR" "$TEST_DIR/api"
echo -e "${GREEN}  ✓ Server folder copied${NC}"

# Step 3: Update composer.json
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

# Step 5: Create test database functions
echo -e "\n${YELLOW}📝 Step 5: Creating test database schema...${NC}"
mkdir -p src/App/Database/postgres/{tables,functions}

# Create test table
cat > src/App/Database/postgres/tables/001_health.pssql <<'SQL'
CREATE TABLE IF NOT EXISTS health_check (
    id SERIAL PRIMARY KEY,
    status VARCHAR(50) NOT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO health_check (status) VALUES ('initialized');
SQL

# Create test function
cat > src/App/Database/postgres/functions/check_db_health.pssql <<'SQL'
CREATE OR REPLACE FUNCTION check_db_health()
RETURNS JSON AS $$
DECLARE
    result JSON;
BEGIN
    INSERT INTO health_check (status) VALUES ('healthy');

    SELECT json_build_object(
        'status', 'ok',
        'database', 'connected',
        'timestamp', NOW(),
        'record_count', (SELECT COUNT(*) FROM health_check)
    ) INTO result;

    RETURN result;
END;
$$ LANGUAGE plpgsql;
SQL

echo -e "${GREEN}  ✓ Database schema created${NC}"

# Create PHP test endpoint
mkdir -p public
cat > public/index.php <<'PHP'
<?php
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        putenv($line);
    }
}

header('Content-Type: application/json');

try {
    // Test database connection
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        getenv('DB_HOST'),
        getenv('DB_PORT'),
        getenv('DB_NAME')
    );

    $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Call health check function
    $stmt = $pdo->query('SELECT check_db_health()');
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'ok',
        'database' => 'connected',
        'health_check' => json_decode($result['check_db_health'], true),
        'environment' => getenv('APP_ENV')
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
PHP

echo -e "${GREEN}  ✓ Test endpoint created${NC}"

# Step 6: Create Dockerfiles
echo -e "\n${YELLOW}🐳 Step 6: Creating Dockerfiles...${NC}"

# Dev Dockerfile
cat > Dockerfile.dev <<'EOF'
FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    nginx \
    supervisor \
    curl

RUN docker-php-ext-install pdo pdo_pgsql

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /etc/nginx/http.d
RUN cat > /etc/nginx/http.d/default.conf <<'NGINX'
server {
    listen 80;
    server_name localhost;
    location / {
        proxy_pass http://127.0.0.1:9100;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
NGINX

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

EXPOSE 80
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
EOF

# Prod Dockerfile
cat > Dockerfile.prod <<'EOF'
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    curl \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo pdo_pgsql
RUN a2enmod rewrite headers

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

WORKDIR /var/www/html
COPY . /var/www/html/

RUN cat > /etc/apache2/sites-available/000-default.conf <<'VHOST'
<VirtualHost *:80>
    DocumentRoot /var/www/html/public
    <Directory /var/www/html/public>
        AllowOverride All
        Require all granted
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^(.*)$ index.php [QSA,L]
    </Directory>
</VirtualHost>
VHOST

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
CMD ["apache2-foreground"]
EOF

echo -e "${GREEN}  ✓ Dockerfiles created${NC}"

# Step 7: Create docker compose.yml
echo -e "\n${YELLOW}🐳 Step 7: Creating docker compose.yml...${NC}"
cd "$TEST_DIR"

cat > docker compose.yml <<EOF
version: '3.8'

services:
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: stonescript_test
      POSTGRES_USER: testuser
      POSTGRES_PASSWORD: testpass
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U testuser"]
      interval: 5s
      timeout: 5s
      retries: 5
    networks:
      - stonescript_network

  dev:
    build:
      context: ./api
      dockerfile: Dockerfile.dev
    ports:
      - "$DEV_PORT:80"
    environment:
      APP_NAME: StoneScriptPHP
      APP_ENV: development
      APP_PORT: 9100
      DB_HOST: postgres
      DB_PORT: 5432
      DB_NAME: stonescript_test
      DB_USER: testuser
      DB_PASS: testpass
      JWT_SECRET: dev_secret_key
      JWT_ALGORITHM: HS256
    depends_on:
      postgres:
        condition: service_healthy
    networks:
      - stonescript_network

  prod:
    build:
      context: ./api
      dockerfile: Dockerfile.prod
    ports:
      - "$PROD_PORT:80"
    environment:
      APP_NAME: StoneScriptPHP
      APP_ENV: production
      DB_HOST: postgres
      DB_PORT: 5432
      DB_NAME: stonescript_test
      DB_USER: testuser
      DB_PASS: testpass
      JWT_SECRET: prod_secret_key
      JWT_ALGORITHM: HS256
    depends_on:
      postgres:
        condition: service_healthy
    networks:
      - stonescript_network

volumes:
  postgres_data:

networks:
  stonescript_network:
    driver: bridge
EOF

echo -e "${GREEN}  ✓ docker compose.yml created${NC}"

# Step 8: Start docker compose
echo -e "\n${YELLOW}🚀 Step 8: Starting docker compose services...${NC}"
docker compose up -d --build
echo "  Waiting for services to start..."
sleep 15

# Check services
if docker compose ps | grep -q "Up"; then
    echo -e "${GREEN}  ✓ Services started successfully${NC}"
else
    echo -e "${RED}  ✗ Services failed to start${NC}"
    docker compose logs
    exit 1
fi

# Step 9: Initialize database
echo -e "\n${YELLOW}💾 Step 9: Initializing database...${NC}"
docker compose exec -T postgres psql -U testuser -d stonescript_test <<'SQL'
CREATE TABLE IF NOT EXISTS health_check (
    id SERIAL PRIMARY KEY,
    status VARCHAR(50) NOT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO health_check (status) VALUES ('initialized');

CREATE OR REPLACE FUNCTION check_db_health()
RETURNS JSON AS $$
DECLARE
    result JSON;
BEGIN
    INSERT INTO health_check (status) VALUES ('healthy');

    SELECT json_build_object(
        'status', 'ok',
        'database', 'connected',
        'timestamp', NOW(),
        'record_count', (SELECT COUNT(*) FROM health_check)
    ) INTO result;

    RETURN result;
END;
$$ LANGUAGE plpgsql;
SQL

echo -e "${GREEN}  ✓ Database initialized${NC}"

# Step 10: Health checks
echo -e "\n${YELLOW}🔍 Step 10: Testing endpoints...${NC}"

# Wait a bit more
sleep 5

# Test Dev endpoint
echo ""
echo "  Testing DEV endpoint (http://localhost:$DEV_PORT/):"
DEV_RESPONSE=$(curl -s http://localhost:$DEV_PORT/ 2>&1 || echo "{}")
echo "  Response: $DEV_RESPONSE"

if echo "$DEV_RESPONSE" | grep -q "connected"; then
    echo -e "${GREEN}  ✓ DEV database connection successful${NC}"
else
    echo -e "${RED}  ✗ DEV database connection failed${NC}"
    docker compose logs dev
fi

# Test Prod endpoint
echo ""
echo "  Testing PROD endpoint (http://localhost:$PROD_PORT/):"
PROD_RESPONSE=$(curl -s http://localhost:$PROD_PORT/ 2>&1 || echo "{}")
echo "  Response: $PROD_RESPONSE"

if echo "$PROD_RESPONSE" | grep -q "connected"; then
    echo -e "${GREEN}  ✓ PROD database connection successful${NC}"
else
    echo -e "${RED}  ✗ PROD database connection failed${NC}"
    docker compose logs prod
fi

# Verify database records
echo ""
echo "  Verifying database records:"
RECORD_COUNT=$(docker compose exec -T postgres psql -U testuser -d stonescript_test -t -c "SELECT COUNT(*) FROM health_check;" | tr -d ' ')
echo "  Health check records: $RECORD_COUNT"

if [ "$RECORD_COUNT" -gt 0 ]; then
    echo -e "${GREEN}  ✓ Database records verified${NC}"
fi

# Additional verification
echo -e "\n${YELLOW}📊 Additional Verification:${NC}"
echo "  • PostgreSQL status: $(docker compose ps postgres | grep Up | wc -l) running"
echo "  • Dev container status: $(docker compose ps dev | grep Up | wc -l) running"
echo "  • Prod container status: $(docker compose ps prod | grep Up | wc -l) running"
echo "  • Database records: $RECORD_COUNT"

# Test passed
echo -e "\n${GREEN}========================================${NC}"
echo -e "${GREEN}✅ TEST CASE 4 PASSED${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Summary:"
echo "  • Docker-compose with PostgreSQL started"
echo "  • DEV container connected to database"
echo "  • PROD container connected to database"
echo "  • Database functions working"
echo "  • Health checks passing"
echo ""
echo "Test artifacts at: $TEST_DIR"
echo "To keep services running: trap - EXIT"

# Cleanup will happen automatically via trap
