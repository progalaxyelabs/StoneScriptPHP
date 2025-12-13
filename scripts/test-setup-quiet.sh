#!/bin/bash
# Test script for setup --quiet mode

set -e  # Exit on error

echo "=== Testing 'php stone setup --quiet' ==="
echo ""

# Test 1: Quiet mode with existing .env
echo "Test 1: Quiet mode with existing .env"
echo "----------------------------------------"
if [ -f .env ]; then
    echo "✓ .env exists"
    BEFORE_ISSUER=$(grep "JWT_ISSUER=" .env | cut -d'=' -f2)
    echo "  JWT_ISSUER before: $BEFORE_ISSUER"

    # Run quiet setup
    php cli/setup.php --quiet

    AFTER_ISSUER=$(grep "JWT_ISSUER=" .env | cut -d'=' -f2)
    echo "  JWT_ISSUER after: $AFTER_ISSUER"

    if [ "$BEFORE_ISSUER" = "$AFTER_ISSUER" ]; then
        echo "✓ PASS: Existing .env values preserved"
    else
        echo "✗ FAIL: .env values changed"
        exit 1
    fi
else
    echo "ℹ️  SKIP: No existing .env"
fi
echo ""

# Test 2: Test with -q shorthand
echo "Test 2: Test -q shorthand"
echo "----------------------------------------"
php cli/setup.php -q > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "✓ PASS: -q flag works"
else
    echo "✗ FAIL: -q flag failed"
    exit 1
fi
echo ""

# Test 3: Verify no output in quiet mode
echo "Test 3: Verify no output in quiet mode"
echo "----------------------------------------"
OUTPUT=$(php cli/setup.php --quiet 2>&1)
if [ -z "$OUTPUT" ]; then
    echo "✓ PASS: No output in quiet mode"
else
    echo "✗ FAIL: Unexpected output: $OUTPUT"
    exit 1
fi
echo ""

# Test 4: Verify .env was created/preserved
echo "Test 4: Verify .env exists"
echo "----------------------------------------"
if [ -f .env ]; then
    echo "✓ PASS: .env file exists"

    # Check required JWT fields
    if grep -q "JWT_ISSUER=" .env && \
       grep -q "JWT_ACCESS_TOKEN_EXPIRY=" .env && \
       grep -q "JWT_REFRESH_TOKEN_EXPIRY=" .env; then
        echo "✓ PASS: All JWT fields present"
    else
        echo "✗ FAIL: Missing JWT fields"
        exit 1
    fi
else
    echo "✗ FAIL: .env not created"
    exit 1
fi
echo ""

# Test 5: Key generation skip when keys exist
echo "Test 5: Key generation skip when keys exist"
echo "----------------------------------------"
if [ -f ./stone-script-php-jwt.pem ] && [ -f ./stone-script-php-jwt.pub ]; then
    echo "✓ Keys already exist, should skip generation"

    # Get modification time before
    BEFORE_MTIME=$(stat -c %Y ./stone-script-php-jwt.pem 2>/dev/null || stat -f %m ./stone-script-php-jwt.pem)

    # Run quiet setup
    php cli/setup.php --quiet

    # Get modification time after
    AFTER_MTIME=$(stat -c %Y ./stone-script-php-jwt.pem 2>/dev/null || stat -f %m ./stone-script-php-jwt.pem)

    if [ "$BEFORE_MTIME" = "$AFTER_MTIME" ]; then
        echo "✓ PASS: Keys not regenerated"
    else
        echo "✗ FAIL: Keys were regenerated"
        exit 1
    fi
else
    echo "ℹ️  SKIP: No existing keys"
fi
echo ""

echo "=== All Tests Passed! ==="
echo ""
echo "Summary:"
echo "  • Quiet mode preserves existing .env"
echo "  • -q shorthand works"
echo "  • No output in quiet mode"
echo "  • .env created with JWT config"
echo "  • Skips key regeneration when keys exist"
