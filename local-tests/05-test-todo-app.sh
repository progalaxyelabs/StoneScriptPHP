#!/bin/bash
set -e

# Test Case 5: Docker-compose TODO App Full Integration Test
# Tests: Complete TODO app with CRUD operations using PostgreSQL

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${YELLOW}========================================${NC}"
echo -e "${YELLOW}Test Case 5: TODO App Integration${NC}"
echo -e "${YELLOW}========================================${NC}"
echo ""

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FRAMEWORK_DIR="$(dirname "$SCRIPT_DIR")"
SERVER_DIR="$(dirname "$FRAMEWORK_DIR")/StoneScriptPHP-Server"
TEST_DIR="/tmp/test-stonescriptphp-todo"
API_PORT=9155

echo "📋 Configuration:"
echo "  Framework: $FRAMEWORK_DIR"
echo "  Server: $SERVER_DIR"
echo "  Test Dir: $TEST_DIR"
echo "  API Port: $API_PORT"
echo ""

# Cleanup function
cleanup() {
    echo -e "\n${YELLOW}🧹 Cleaning up...${NC}"

    if [ -f "$TEST_DIR/docker-compose.yml" ]; then
        cd "$TEST_DIR"
        echo "  Stopping docker-compose services..."
        docker-compose down -v 2>/dev/null || true
    fi

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

# Step 5: Create TODO app database schema
echo -e "\n${YELLOW}📝 Step 5: Creating TODO app schema...${NC}"
mkdir -p src/App/Database/postgres/{tables,functions}

# Create todos table
cat > src/App/Database/postgres/tables/001_todos.pssql <<'SQL'
CREATE TABLE IF NOT EXISTS todos (
    id SERIAL PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    completed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
SQL

# Create function: list all todos
cat > src/App/Database/postgres/functions/list_todos.pssql <<'SQL'
CREATE OR REPLACE FUNCTION list_todos()
RETURNS JSON AS $$
BEGIN
    RETURN (
        SELECT COALESCE(json_agg(
            json_build_object(
                'id', id,
                'title', title,
                'description', description,
                'completed', completed,
                'created_at', created_at,
                'updated_at', updated_at
            )
        ORDER BY created_at DESC
        ), '[]'::json)
        FROM todos
    );
END;
$$ LANGUAGE plpgsql;
SQL

# Create function: create todo
cat > src/App/Database/postgres/functions/create_todo.pssql <<'SQL'
CREATE OR REPLACE FUNCTION create_todo(
    p_title VARCHAR,
    p_description TEXT DEFAULT NULL
)
RETURNS JSON AS $$
DECLARE
    result JSON;
BEGIN
    INSERT INTO todos (title, description)
    VALUES (p_title, p_description)
    RETURNING json_build_object(
        'id', id,
        'title', title,
        'description', description,
        'completed', completed,
        'created_at', created_at,
        'updated_at', updated_at
    ) INTO result;

    RETURN result;
END;
$$ LANGUAGE plpgsql;
SQL

# Create function: get todo by id
cat > src/App/Database/postgres/functions/get_todo.pssql <<'SQL'
CREATE OR REPLACE FUNCTION get_todo(p_id INT)
RETURNS JSON AS $$
DECLARE
    result JSON;
BEGIN
    SELECT json_build_object(
        'id', id,
        'title', title,
        'description', description,
        'completed', completed,
        'created_at', created_at,
        'updated_at', updated_at
    ) INTO result
    FROM todos
    WHERE id = p_id;

    RETURN result;
END;
$$ LANGUAGE plpgsql;
SQL

# Create function: update todo
cat > src/App/Database/postgres/functions/update_todo.pssql <<'SQL'
CREATE OR REPLACE FUNCTION update_todo(
    p_id INT,
    p_title VARCHAR DEFAULT NULL,
    p_description TEXT DEFAULT NULL,
    p_completed BOOLEAN DEFAULT NULL
)
RETURNS JSON AS $$
DECLARE
    result JSON;
BEGIN
    UPDATE todos
    SET
        title = COALESCE(p_title, title),
        description = COALESCE(p_description, description),
        completed = COALESCE(p_completed, completed),
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_id
    RETURNING json_build_object(
        'id', id,
        'title', title,
        'description', description,
        'completed', completed,
        'created_at', created_at,
        'updated_at', updated_at
    ) INTO result;

    RETURN result;
END;
$$ LANGUAGE plpgsql;
SQL

# Create function: delete todo
cat > src/App/Database/postgres/functions/delete_todo.pssql <<'SQL'
CREATE OR REPLACE FUNCTION delete_todo(p_id INT)
RETURNS JSON AS $$
DECLARE
    deleted_count INT;
BEGIN
    DELETE FROM todos WHERE id = p_id;
    GET DIAGNOSTICS deleted_count = ROW_COUNT;

    RETURN json_build_object(
        'deleted', deleted_count > 0,
        'id', p_id
    );
END;
$$ LANGUAGE plpgsql;
SQL

echo -e "${GREEN}  ✓ Database schema created (5 functions)${NC}"

# Step 6: Create REST API endpoints
echo -e "\n${YELLOW}🌐 Step 6: Creating REST API endpoints...${NC}"
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

// Database connection
function getDb() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            getenv('DB_HOST'),
            getenv('DB_PORT'),
            getenv('DB_NAME')
        );
        $pdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

// JSON response helper
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/');

try {
    $db = getDb();

    // Route: GET /todos - List all todos
    if ($method === 'GET' && $path === '/todos') {
        $stmt = $db->query('SELECT list_todos() as result');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        jsonResponse(json_decode($result['result'], true) ?: []);
    }

    // Route: POST /todos - Create todo
    if ($method === 'POST' && $path === '/todos') {
        $input = json_decode(file_get_contents('php://input'), true);
        $title = $input['title'] ?? '';
        $description = $input['description'] ?? null;

        if (empty($title)) {
            jsonResponse(['error' => 'Title is required'], 400);
        }

        $stmt = $db->prepare('SELECT create_todo(?, ?) as result');
        $stmt->execute([$title, $description]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        jsonResponse(json_decode($result['result'], true), 201);
    }

    // Route: GET /todos/:id - Get single todo
    if ($method === 'GET' && preg_match('#^/todos/(\d+)$#', $path, $matches)) {
        $id = (int)$matches[1];
        $stmt = $db->prepare('SELECT get_todo(?) as result');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $todo = json_decode($result['result'], true);

        if ($todo) {
            jsonResponse($todo);
        } else {
            jsonResponse(['error' => 'Todo not found'], 404);
        }
    }

    // Route: PUT /todos/:id - Update todo
    if ($method === 'PUT' && preg_match('#^/todos/(\d+)$#', $path, $matches)) {
        $id = (int)$matches[1];
        $input = json_decode(file_get_contents('php://input'), true);

        $title = $input['title'] ?? null;
        $description = $input['description'] ?? null;
        $completed = isset($input['completed']) ? (bool)$input['completed'] : null;

        $stmt = $db->prepare('SELECT update_todo(?, ?, ?, ?) as result');
        $stmt->execute([$id, $title, $description, $completed]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $todo = json_decode($result['result'], true);

        if ($todo) {
            jsonResponse($todo);
        } else {
            jsonResponse(['error' => 'Todo not found'], 404);
        }
    }

    // Route: DELETE /todos/:id - Delete todo
    if ($method === 'DELETE' && preg_match('#^/todos/(\d+)$#', $path, $matches)) {
        $id = (int)$matches[1];
        $stmt = $db->prepare('SELECT delete_todo(?) as result');
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        jsonResponse(json_decode($result['result'], true));
    }

    // Route: GET / - Health check
    if ($method === 'GET' && $path === '') {
        jsonResponse([
            'status' => 'ok',
            'app' => 'TODO API',
            'endpoints' => [
                'GET /todos' => 'List all todos',
                'POST /todos' => 'Create todo',
                'GET /todos/:id' => 'Get todo by id',
                'PUT /todos/:id' => 'Update todo',
                'DELETE /todos/:id' => 'Delete todo'
            ]
        ]);
    }

    // 404
    jsonResponse(['error' => 'Not found'], 404);

} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
PHP

echo -e "${GREEN}  ✓ REST API created${NC}"

# Step 7: Create Dockerfile
echo -e "\n${YELLOW}🐳 Step 7: Creating Dockerfile...${NC}"

cat > Dockerfile <<'EOF'
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

echo -e "${GREEN}  ✓ Dockerfile created${NC}"

# Step 8: Create docker-compose.yml
echo -e "\n${YELLOW}🐳 Step 8: Creating docker-compose.yml...${NC}"
cd "$TEST_DIR"

cat > docker-compose.yml <<EOF
version: '3.8'

services:
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: todo_db
      POSTGRES_USER: todo_user
      POSTGRES_PASSWORD: todo_pass
    volumes:
      - postgres_data:/var/lib/postgresql/data
      - ./api/src/App/Database/postgres:/docker-entrypoint-initdb.d/sql
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U todo_user"]
      interval: 5s
      timeout: 5s
      retries: 5
    networks:
      - todo_network

  api:
    build:
      context: ./api
      dockerfile: Dockerfile
    ports:
      - "$API_PORT:80"
    environment:
      APP_NAME: TodoAPI
      APP_ENV: production
      DB_HOST: postgres
      DB_PORT: 5432
      DB_NAME: todo_db
      DB_USER: todo_user
      DB_PASS: todo_pass
      JWT_SECRET: todo_secret_key
      JWT_ALGORITHM: HS256
    depends_on:
      postgres:
        condition: service_healthy
    networks:
      - todo_network

volumes:
  postgres_data:

networks:
  todo_network:
    driver: bridge
EOF

echo -e "${GREEN}  ✓ docker-compose.yml created${NC}"

# Step 9: Start services
echo -e "\n${YELLOW}🚀 Step 9: Starting services...${NC}"
docker-compose up -d --build
echo "  Waiting for services to start..."
sleep 15

if docker-compose ps | grep -q "Up"; then
    echo -e "${GREEN}  ✓ Services started${NC}"
else
    echo -e "${RED}  ✗ Services failed to start${NC}"
    docker-compose logs
    exit 1
fi

# Step 10: Initialize database
echo -e "\n${YELLOW}💾 Step 10: Initializing database...${NC}"
docker-compose exec -T postgres psql -U todo_user -d todo_db < api/src/App/Database/postgres/tables/001_todos.pssql
for func in api/src/App/Database/postgres/functions/*.pssql; do
    docker-compose exec -T postgres psql -U todo_user -d todo_db < "$func"
done
echo -e "${GREEN}  ✓ Database initialized${NC}"

# Step 11: Run CRUD tests
echo -e "\n${YELLOW}🧪 Step 11: Running CRUD tests...${NC}"

API_URL="http://localhost:$API_PORT"

# Test 1: Health check
echo -e "\n${BLUE}Test 1: Health Check${NC}"
RESPONSE=$(curl -s $API_URL/)
echo "  Response: $RESPONSE"
if echo "$RESPONSE" | grep -q "TODO API"; then
    echo -e "${GREEN}  ✓ Health check passed${NC}"
else
    echo -e "${RED}  ✗ Health check failed${NC}"
    exit 1
fi

# Test 2: List todos (empty)
echo -e "\n${BLUE}Test 2: List Todos (Empty)${NC}"
RESPONSE=$(curl -s $API_URL/todos)
echo "  Response: $RESPONSE"
if echo "$RESPONSE" | grep -q "\[\]"; then
    echo -e "${GREEN}  ✓ Empty list returned${NC}"
else
    echo -e "${RED}  ✗ Failed to get empty list${NC}"
    exit 1
fi

# Test 3: Create todo
echo -e "\n${BLUE}Test 3: Create Todo${NC}"
RESPONSE=$(curl -s -X POST $API_URL/todos \
    -H "Content-Type: application/json" \
    -d '{"title":"Buy groceries","description":"Milk, eggs, bread"}')
echo "  Response: $RESPONSE"
TODO_ID=$(echo "$RESPONSE" | grep -o '"id":[0-9]*' | grep -o '[0-9]*')
if [ ! -z "$TODO_ID" ]; then
    echo -e "${GREEN}  ✓ Todo created with ID: $TODO_ID${NC}"
else
    echo -e "${RED}  ✗ Failed to create todo${NC}"
    exit 1
fi

# Test 4: Create another todo
echo -e "\n${BLUE}Test 4: Create Another Todo${NC}"
RESPONSE=$(curl -s -X POST $API_URL/todos \
    -H "Content-Type: application/json" \
    -d '{"title":"Write tests","description":"Complete integration tests"}')
echo "  Response: $RESPONSE"
TODO_ID2=$(echo "$RESPONSE" | grep -o '"id":[0-9]*' | grep -o '[0-9]*')
if [ ! -z "$TODO_ID2" ]; then
    echo -e "${GREEN}  ✓ Second todo created with ID: $TODO_ID2${NC}"
else
    echo -e "${RED}  ✗ Failed to create second todo${NC}"
    exit 1
fi

# Test 5: List todos
echo -e "\n${BLUE}Test 5: List All Todos${NC}"
RESPONSE=$(curl -s $API_URL/todos)
echo "  Response: $RESPONSE"
TODO_COUNT=$(echo "$RESPONSE" | grep -o '"id"' | wc -l)
if [ "$TODO_COUNT" -eq 2 ]; then
    echo -e "${GREEN}  ✓ Found 2 todos${NC}"
else
    echo -e "${RED}  ✗ Expected 2 todos, found $TODO_COUNT${NC}"
    exit 1
fi

# Test 6: Get single todo
echo -e "\n${BLUE}Test 6: Get Single Todo${NC}"
RESPONSE=$(curl -s $API_URL/todos/$TODO_ID)
echo "  Response: $RESPONSE"
if echo "$RESPONSE" | grep -q "Buy groceries"; then
    echo -e "${GREEN}  ✓ Retrieved todo by ID${NC}"
else
    echo -e "${RED}  ✗ Failed to get todo${NC}"
    exit 1
fi

# Test 7: Update todo
echo -e "\n${BLUE}Test 7: Update Todo (Mark Complete)${NC}"
RESPONSE=$(curl -s -X PUT $API_URL/todos/$TODO_ID \
    -H "Content-Type: application/json" \
    -d '{"completed":true}')
echo "  Response: $RESPONSE"
if echo "$RESPONSE" | grep -q '"completed":true'; then
    echo -e "${GREEN}  ✓ Todo marked as completed${NC}"
else
    echo -e "${RED}  ✗ Failed to update todo${NC}"
    exit 1
fi

# Test 8: Delete todo
echo -e "\n${BLUE}Test 8: Delete Todo${NC}"
RESPONSE=$(curl -s -X DELETE $API_URL/todos/$TODO_ID2)
echo "  Response: $RESPONSE"
if echo "$RESPONSE" | grep -q '"deleted":true'; then
    echo -e "${GREEN}  ✓ Todo deleted${NC}"
else
    echo -e "${RED}  ✗ Failed to delete todo${NC}"
    exit 1
fi

# Test 9: Verify deletion
echo -e "\n${BLUE}Test 9: Verify Deletion${NC}"
RESPONSE=$(curl -s $API_URL/todos)
echo "  Response: $RESPONSE"
TODO_COUNT=$(echo "$RESPONSE" | grep -o '"id"' | wc -l)
if [ "$TODO_COUNT" -eq 1 ]; then
    echo -e "${GREEN}  ✓ Only 1 todo remaining${NC}"
else
    echo -e "${RED}  ✗ Expected 1 todo, found $TODO_COUNT${NC}"
    exit 1
fi

# Test passed
echo -e "\n${GREEN}========================================${NC}"
echo -e "${GREEN}✅ TEST CASE 5 PASSED - ALL TESTS PASSED${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "Summary:"
echo "  • Full TODO app deployed with docker-compose"
echo "  • PostgreSQL database with 5 functions"
echo "  • REST API with 5 endpoints"
echo "  • All CRUD operations tested successfully:"
echo "    ✓ Create Todo"
echo "    ✓ Read Todo(s)"
echo "    ✓ Update Todo"
echo "    ✓ Delete Todo"
echo ""
echo "Test artifacts at: $TEST_DIR"
echo "API running at: http://localhost:$API_PORT"
echo "To keep services running: trap - EXIT"

# Cleanup will happen automatically via trap
