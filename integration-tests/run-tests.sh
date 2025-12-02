#!/bin/bash
set -e

echo "🧪 StoneScriptPHP Documentation Validation Tests"
echo "================================================"

# Start Docker environment
echo "📦 Starting test environment..."
docker-compose up -d
sleep 5

# Wait for PostgreSQL
echo "⏳ Waiting for PostgreSQL..."
docker-compose exec -T postgres pg_isready -U testuser

# Run all tests in order
for test_file in tests/*.sh; do
    echo ""
    echo "▶️  Running: $(basename $test_file)"
    echo "-------------------------------------------"

    if docker-compose exec -T php-test bash /workspace/integration-tests/$test_file; then
        echo "✅ PASSED: $(basename $test_file)"
    else
        echo "❌ FAILED: $(basename $test_file)"
        exit 1
    fi
done

echo ""
echo "🎉 All documentation tests passed!"

# Cleanup
echo "🧹 Cleaning up..."
docker-compose down -v
