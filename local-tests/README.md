# StoneScriptPHP Local Testing Suite

Comprehensive integration tests for local development and validation of StoneScriptPHP framework and server before publishing to Packagist.

## Overview

This test suite validates that the framework and server work correctly together by:
- Using composer path repositories to test local changes
- Testing various deployment scenarios (local, Docker dev, Docker prod)
- Validating database connectivity and operations
- Building a complete TODO app as a real-world integration test

## Prerequisites

- PHP >= 8.2
- Composer
- Docker and Docker Compose
- curl (for API testing)
- PostgreSQL client (optional, for manual DB inspection)

## Test Cases

### Test 1: Local Server Health Check
**Script:** `01-test-local-health.sh`

Tests basic server functionality without Docker:
- Copies server skeleton to `/tmp/test-stonescriptphp-health`
- Modifies composer.json to use local framework via path repository
- Runs `composer install` to install local framework
- Starts development server with `php stone serve`
- Makes HTTP request to verify server responds
- **Duration:** ~30 seconds

### Test 2: Dev Docker with Nginx
**Script:** `02-test-dev-docker.sh`

Tests development Docker setup with nginx reverse proxy:
- Creates Docker image with PHP-FPM + Nginx
- Uses Supervisor to manage multiple processes
- Nginx proxies requests to `php stone serve`
- Tests on port 9151
- **Duration:** ~1-2 minutes (includes Docker build)

### Test 3: Prod Docker with Apache
**Script:** `03-test-prod-docker.sh`

Tests production Docker setup with Apache:
- Creates production-optimized Docker image
- Apache serves from `public/` directory
- Uses mod_rewrite for clean URLs
- Production composer dependencies (--no-dev)
- Tests on port 9152
- **Duration:** ~1-2 minutes (includes Docker build)

### Test 4: Docker-Compose + PostgreSQL
**Script:** `04-test-docker-compose-db.sh`

Tests database connectivity with docker-compose:
- Spins up PostgreSQL container
- Runs both dev and prod containers
- Creates test database schema and functions
- Verifies database connections from both containers
- Tests SQL function execution
- **Duration:** ~2-3 minutes

### Test 5: TODO App Full Integration
**Script:** `05-test-todo-app.sh`

Complete CRUD application test:
- Creates full TODO app with:
  - PostgreSQL database (1 table, 5 functions)
  - REST API (5 endpoints)
  - Docker-compose setup
- Tests all CRUD operations:
  - ✅ Create Todo
  - ✅ Read Todo(s)
  - ✅ Update Todo
  - ✅ Delete Todo
- Verifies data persistence
- Tests on port 9155
- **Duration:** ~3-4 minutes

## Usage

### Run All Tests

```bash
cd local-tests
chmod +x *.sh
./run-all-tests.sh
```

### Run Individual Tests

```bash
# Test 1: Local health check
./01-test-local-health.sh

# Test 2: Dev Docker
./02-test-dev-docker.sh

# Test 3: Prod Docker
./03-test-prod-docker.sh

# Test 4: Docker-compose DB
./04-test-docker-compose-db.sh

# Test 5: TODO app
./05-test-todo-app.sh
```

### Run Selected Tests

```bash
# Run only tests 1 and 5
./run-all-tests.sh 1 5

# Run only Docker tests
./run-all-tests.sh 2 3 4
```

## How It Works

### Path Repository Strategy

Each test uses Composer's path repository feature to test local framework changes:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/path/to/StoneScriptPHP",
            "options": {"symlink": false}
        }
    ],
    "require": {
        "progalaxyelabs/stonescriptphp": "@dev"
    }
}
```

This approach:
- ✅ Mimics real Packagist installation
- ✅ Tests actual composer dependency resolution
- ✅ No manual file copying needed
- ✅ Can iterate quickly (make changes → composer update)

### Test Isolation

Each test:
- Creates a unique temporary directory (`/tmp/test-stonescriptphp-*`)
- Runs in complete isolation
- Cleans up automatically on exit (via trap)
- Uses different ports to avoid conflicts

### Cleanup

All tests clean up automatically:
- Stops running servers/containers
- Removes Docker images and volumes
- Deletes temporary directories

To keep test artifacts for inspection:
```bash
# Run test and keep artifacts
trap - EXIT
./01-test-local-health.sh
```

## Test Output

### Success Example
```
========================================
Test Case 1: Local Server Health Check
========================================

📋 Configuration:
  Framework: /ssd2/projects/.../StoneScriptPHP
  Server: /ssd2/projects/.../StoneScriptPHP-Server
  Test Dir: /tmp/test-stonescriptphp-health
  Port: 9100

📁 Step 1: Creating test directory...
  ✓ Test directory created

📦 Step 2: Copying server folder...
  ✓ Server folder copied

🔧 Step 3: Updating composer.json...
  ✓ composer.json updated

📥 Step 4: Running composer install...
  ✓ Composer dependencies installed
  ✓ Framework installed correctly

🚀 Step 5: Starting PHP development server...
  ✓ Server started successfully

🔍 Step 6: Testing health endpoint...
  ✓ Health check passed (HTTP 200)

========================================
✅ TEST CASE 1 PASSED
========================================
```

### Master Test Runner Output
```
╔════════════════════════════════════════════╗
║  StoneScriptPHP Local Testing Suite       ║
║  Comprehensive Integration Tests           ║
╚════════════════════════════════════════════╝

📋 Test Plan:
  Running all 5 test cases...

⏱️  Total Duration: 8m 32s

✅ Passed Tests (5/5):
   ✓ Local Server Health Check (28s)
   ✓ Dev Docker with Nginx (1m 45s)
   ✓ Prod Docker with Apache (1m 52s)
   ✓ Docker-Compose + PostgreSQL (2m 18s)
   ✓ TODO App Integration (3m 9s)

╔════════════════════════════════════════════╗
║     🎉 ALL TESTS PASSED! 🎉               ║
╚════════════════════════════════════════════╝

You can now safely commit and push your changes.
```

## Troubleshooting

### Test Fails to Start

**Issue:** "Server directory not found"
```bash
# Verify paths in the script
FRAMEWORK_DIR="/ssd2/projects/.../StoneScriptPHP"
SERVER_DIR="/ssd2/projects/.../StoneScriptPHP-Server"

# Update if your paths are different
```

### Port Already in Use

**Issue:** "Address already in use"
```bash
# Find process using the port
lsof -i :9100

# Kill the process
kill -9 <PID>

# Or change the port in the test script
TEST_PORT=9200
```

### Docker Build Fails

**Issue:** "Docker build failed"
```bash
# Check Docker is running
docker ps

# Clean Docker cache
docker system prune -a

# Check disk space
df -h
```

### Composer Install Fails

**Issue:** "Could not find package"
```bash
# Verify framework path exists
ls -la /ssd2/projects/.../StoneScriptPHP

# Check composer.json syntax
composer validate

# Clear composer cache
composer clear-cache
```

### Database Connection Fails

**Issue:** "Could not connect to database"
```bash
# Check PostgreSQL is running
docker-compose ps postgres

# Check logs
docker-compose logs postgres

# Manually test connection
docker-compose exec postgres psql -U testuser -d stonescript_test
```

## Development Workflow

### Typical Usage Pattern

1. **Make changes** to framework or server
2. **Run quick test** (test 1 for fast feedback):
   ```bash
   ./01-test-local-health.sh
   ```
3. **Run Docker tests** if changes affect containerization:
   ```bash
   ./02-test-dev-docker.sh
   ./03-test-prod-docker.sh
   ```
4. **Run full suite** before committing:
   ```bash
   ./run-all-tests.sh
   ```
5. **Commit and push** when all tests pass

### Iterative Testing

When debugging a specific test:
```bash
# Keep test artifacts
trap - EXIT

# Run test
./01-test-local-health.sh

# Inspect test directory
cd /tmp/test-stonescriptphp-health
ls -la

# Make changes to framework
cd /ssd2/projects/.../StoneScriptPHP
# edit files...

# Update in test directory
cd /tmp/test-stonescriptphp-health/api
composer update progalaxyelabs/stonescriptphp

# Manually test
php stone serve
curl http://localhost:9100/
```

## CI/CD Integration

These tests can be integrated into CI/CD pipelines:

### GitHub Actions Example
```yaml
name: Local Integration Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - name: Install Composer dependencies
        run: composer install

      - name: Run integration tests
        run: |
          cd local-tests
          chmod +x run-all-tests.sh
          ./run-all-tests.sh
```

## Contributing

When adding new tests:

1. Follow the naming convention: `0X-test-description.sh`
2. Include colorized output (use existing color variables)
3. Implement cleanup via trap
4. Add test to `run-all-tests.sh`
5. Document in this README
6. Use descriptive step messages
7. Test both success and failure scenarios

## License

MIT - Same as StoneScriptPHP framework
