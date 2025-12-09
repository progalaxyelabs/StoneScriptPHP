# Quick Start Guide

## Run All Tests (Recommended)

```bash
cd local-tests
./run-all-tests.sh
```

This runs all 5 test cases sequentially. Duration: ~8-10 minutes.

## Run Individual Tests

### Test 1: Quick Health Check (~30 seconds)
```bash
./01-test-local-health.sh
```
Perfect for quick validation after framework changes.

### Test 2: Dev Docker (~2 minutes)
```bash
./02-test-dev-docker.sh
```
Tests development Docker setup with nginx.

### Test 3: Prod Docker (~2 minutes)
```bash
./03-test-prod-docker.sh
```
Tests production Docker setup with Apache.

### Test 4: Database Connection (~3 minutes)
```bash
./04-test-docker-compose-db.sh
```
Tests PostgreSQL connectivity.

### Test 5: Full TODO App (~4 minutes)
```bash
./05-test-todo-app.sh
```
Complete CRUD integration test.

### Test 6: CLI CRUD Generation (~4 minutes)
```bash
./06-test-cli-crud-generation.sh
```
Tests CLI workflow with `php stone generate` commands.

## Run Selected Tests

```bash
# Run only tests 1 and 5
./run-all-tests.sh 1 5

# Run all Docker tests
./run-all-tests.sh 2 3 4
```

## What Gets Tested?

- ✅ Local framework installation via composer path repository
- ✅ Server starts correctly with `php stone serve`
- ✅ Docker containerization (dev and prod)
- ✅ PostgreSQL database connectivity
- ✅ SQL functions and migrations
- ✅ REST API endpoints
- ✅ Complete CRUD operations
- ✅ Real-world TODO application

## Prerequisites

- PHP >= 8.2
- Composer
- Docker & Docker Compose
- curl

## Troubleshooting

### Port already in use
```bash
# Kill process on port 9100
lsof -i :9100
kill -9 <PID>
```

### Docker issues
```bash
# Clean Docker
docker system prune -a

# Check Docker is running
docker ps
```

### Test artifacts remain
```bash
# Clean up test directories
rm -rf /tmp/test-stonescriptphp-*

# Stop all test containers
docker stop $(docker ps -a | grep stonescriptphp | awk '{print $1}')
docker rm $(docker ps -a | grep stonescriptphp | awk '{print $1}')
```

## Development Workflow

1. Make changes to framework or server
2. Run quick test: `./01-test-local-health.sh`
3. If changes affect Docker, run Docker tests
4. Before commit, run full suite: `./run-all-tests.sh`
5. Commit when all tests pass ✅

## Expected Output

All tests should show:
```
========================================
✅ TEST CASE X PASSED
========================================
```

Master test runner shows:
```
╔════════════════════════════════════════════╗
║     🎉 ALL TESTS PASSED! 🎉               ║
╚════════════════════════════════════════════╝
```

## Need Help?

See [README.md](README.md) for detailed documentation.
