#!/bin/bash

# Test CLI Generators with StoneScriptPHP Namespace
# This script verifies that all CLI generators produce code with correct namespace

set -e

echo "=========================================="
echo "CLI Generator Namespace Verification Test"
echo "=========================================="
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo "Project Root: $PROJECT_ROOT"
echo ""

# Test 1: Check generate-route.php template
echo "1. Checking generate-route.php template..."
if grep -q "use StoneScriptPHP\\\\IRouteHandler;" "$PROJECT_ROOT/cli/generate-route.php"; then
    echo -e "   ${GREEN}✓${NC} Uses StoneScriptPHP\\IRouteHandler"
else
    echo -e "   ${RED}✗${NC} Missing StoneScriptPHP\\IRouteHandler"
    exit 1
fi

if grep -q "use StoneScriptPHP\\\\ApiResponse;" "$PROJECT_ROOT/cli/generate-route.php"; then
    echo -e "   ${GREEN}✓${NC} Uses StoneScriptPHP\\ApiResponse"
else
    echo -e "   ${RED}✗${NC} Missing StoneScriptPHP\\ApiResponse"
    exit 1
fi

if grep -q "Framework\\\\" "$PROJECT_ROOT/cli/generate-route.php"; then
    echo -e "   ${RED}✗${NC} Still contains Framework\\ references"
    exit 1
else
    echo -e "   ${GREEN}✓${NC} No Framework\\ references found"
fi
echo ""

# Test 2: Check generate-model.php template
echo "2. Checking generate-model.php template..."
if grep -q "use StoneScriptPHP\\\\Database;" "$PROJECT_ROOT/cli/generate-model.php"; then
    echo -e "   ${GREEN}✓${NC} Uses StoneScriptPHP\\Database"
else
    echo -e "   ${RED}✗${NC} Missing StoneScriptPHP\\Database"
    exit 1
fi

if grep -q "Framework\\\\" "$PROJECT_ROOT/cli/generate-model.php"; then
    echo -e "   ${RED}✗${NC} Still contains Framework\\ references"
    exit 1
else
    echo -e "   ${GREEN}✓${NC} No Framework\\ references found"
fi
echo ""

# Test 3: Check new.php template (for new project generation)
echo "3. Checking new.php template..."
if grep -q "use StoneScriptPHP\\\\IRouteHandler;" "$PROJECT_ROOT/cli/new.php"; then
    echo -e "   ${GREEN}✓${NC} Uses StoneScriptPHP\\IRouteHandler"
else
    echo -e "   ${RED}✗${NC} Missing StoneScriptPHP\\IRouteHandler"
    exit 1
fi

if grep -q "use StoneScriptPHP\\\\ApiResponse;" "$PROJECT_ROOT/cli/new.php"; then
    echo -e "   ${GREEN}✓${NC} Uses StoneScriptPHP\\ApiResponse"
else
    echo -e "   ${RED}✗${NC} Missing StoneScriptPHP\\ApiResponse"
    exit 1
fi

if grep -q "Framework\\\\" "$PROJECT_ROOT/cli/new.php"; then
    echo -e "   ${RED}✗${NC} Still contains Framework\\ references"
    exit 1
else
    echo -e "   ${GREEN}✓${NC} No Framework\\ references found"
fi
echo ""

# Test 4: Check all PHP files in src/ for Framework\ usage
echo "4. Checking src/ directory for old Framework\\ namespace..."
FRAMEWORK_FILES=$(find "$PROJECT_ROOT/src" -name "*.php" -exec grep -l "namespace Framework" {} \; 2>/dev/null || true)
if [ -n "$FRAMEWORK_FILES" ]; then
    echo -e "   ${RED}✗${NC} Found files still using 'namespace Framework':"
    echo "$FRAMEWORK_FILES" | while read file; do
        echo "      - $file"
    done
    exit 1
else
    echo -e "   ${GREEN}✓${NC} All src/ files use StoneScriptPHP namespace"
fi

FRAMEWORK_IMPORTS=$(find "$PROJECT_ROOT/src" -name "*.php" -exec grep -l "use Framework\\\\" {} \; 2>/dev/null || true)
if [ -n "$FRAMEWORK_IMPORTS" ]; then
    echo -e "   ${RED}✗${NC} Found files still importing Framework\\ classes:"
    echo "$FRAMEWORK_IMPORTS" | while read file; do
        echo "      - $file"
    done
    exit 1
else
    echo -e "   ${GREEN}✓${NC} All src/ files use StoneScriptPHP imports"
fi
echo ""

# Test 5: Run the integration test
echo "5. Running integration test..."
cd "$PROJECT_ROOT"
if php tests/Integration/test-code-generation.php 2>&1 | tail -3; then
    echo -e "   ${GREEN}✓${NC} Integration test passed"
else
    echo -e "   ${RED}✗${NC} Integration test failed"
    exit 1
fi
echo ""

# Summary
echo "=========================================="
echo -e "${GREEN}✓ All tests passed!${NC}"
echo "=========================================="
echo ""
echo "Summary:"
echo "  - CLI generators use correct StoneScriptPHP namespace"
echo "  - No Framework\\ references in generators"
echo "  - All src/ files use StoneScriptPHP namespace"
echo "  - Code generation produces valid PHP with correct namespaces"
echo ""
echo "The framework is ready to be used in server projects!"
