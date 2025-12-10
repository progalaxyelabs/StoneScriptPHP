#!/bin/bash

# Master Test Runner for StoneScriptPHP Local Testing
# Runs all test cases in sequence

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Test results tracking
declare -a PASSED_TESTS
declare -a FAILED_TESTS
TOTAL_TESTS=0
START_TIME=$(date +%s)

# Banner
echo -e "${CYAN}"
echo "╔════════════════════════════════════════════╗"
echo "║  StoneScriptPHP Local Testing Suite       ║"
echo "║  Comprehensive Integration Tests           ║"
echo "╚════════════════════════════════════════════╝"
echo -e "${NC}"
echo ""

# Function to run a test
run_test() {
    local test_number=$1
    local test_name=$2
    local test_script=$3

    TOTAL_TESTS=$((TOTAL_TESTS + 1))

    echo -e "${MAGENTA}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${CYAN}Running Test ${test_number}: ${test_name}${NC}"
    echo -e "${MAGENTA}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""

    local test_start=$(date +%s)

    if bash "$SCRIPT_DIR/$test_script"; then
        local test_end=$(date +%s)
        local duration=$((test_end - test_start))
        PASSED_TESTS+=("$test_name (${duration}s)")
        echo ""
        echo -e "${GREEN}✅ Test ${test_number} PASSED in ${duration}s${NC}"
        return 0
    else
        local test_end=$(date +%s)
        local duration=$((test_end - test_start))
        FAILED_TESTS+=("$test_name (${duration}s)")
        echo ""
        echo -e "${RED}❌ Test ${test_number} FAILED after ${duration}s${NC}"
        return 1
    fi
}

# Parse command line arguments
RUN_ALL=true
SELECTED_TESTS=()

if [ $# -gt 0 ]; then
    RUN_ALL=false
    SELECTED_TESTS=("$@")
fi

# Display test plan
echo -e "${YELLOW}📋 Test Plan:${NC}"
echo ""
if [ "$RUN_ALL" = true ]; then
    echo "  Running all 6 test cases:"
else
    echo "  Running selected test cases: ${SELECTED_TESTS[@]}"
fi
echo ""
echo "  1️⃣  Local Server Health Check"
echo "     • Tests basic server setup"
echo "     • Uses local framework via composer"
echo "     • Verifies php stone serve command"
echo ""
echo "  2️⃣  Dev Docker with Nginx"
echo "     • Tests development Docker setup"
echo "     • Nginx reverse proxy"
echo "     • Supervisor managing processes"
echo ""
echo "  3️⃣  Prod Docker with Apache"
echo "     • Tests production Docker setup"
echo "     • Apache web server"
echo "     • Optimized for production"
echo ""
echo "  4️⃣  Docker-Compose + PostgreSQL"
echo "     • Tests database connectivity"
echo "     • Both dev and prod containers"
echo "     • Database health checks"
echo ""
echo "  5️⃣  TODO App Full Integration"
echo "     • Complete CRUD application"
echo "     • Real-world use case"
echo "     • End-to-end testing"
echo ""
echo "  6️⃣  CLI CRUD Generation"
echo "     • Uses php stone CLI commands"
echo "     • Generates models and routes"
echo "     • Books CRUD application"
echo "     • Validates CLI workflow"
echo ""
echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo ""

# Wait for user confirmation
read -p "Press Enter to start tests (or Ctrl+C to cancel)... "
echo ""

# Run tests
should_run_test() {
    local test_num=$1
    if [ "$RUN_ALL" = true ]; then
        return 0
    fi
    for selected in "${SELECTED_TESTS[@]}"; do
        if [ "$selected" = "$test_num" ]; then
            return 0
        fi
    done
    return 1
}

# Test 1: Local Health Check
if should_run_test "1"; then
    run_test "1" "Local Server Health Check" "01-test-local-health.sh"
    echo ""
    sleep 2
fi

# Test 2: Dev Docker
if should_run_test "2"; then
    run_test "2" "Dev Docker with Nginx" "02-test-dev-docker.sh"
    echo ""
    sleep 2
fi

# Test 3: Prod Docker
if should_run_test "3"; then
    run_test "3" "Prod Docker with Apache" "03-test-prod-docker.sh"
    echo ""
    sleep 2
fi

# Test 4: Docker-Compose DB
if should_run_test "4"; then
    run_test "4" "Docker-Compose + PostgreSQL" "04-test-docker compose-db.sh"
    echo ""
    sleep 2
fi

# Test 5: TODO App
if should_run_test "5"; then
    run_test "5" "TODO App Integration" "05-test-todo-app.sh"
    echo ""
    sleep 2
fi

# Test 6: CLI CRUD Generation
if should_run_test "6"; then
    run_test "6" "CLI CRUD Generation" "06-test-cli-crud-generation.sh"
    echo ""
fi

# Calculate total duration
END_TIME=$(date +%s)
TOTAL_DURATION=$((END_TIME - START_TIME))
MINUTES=$((TOTAL_DURATION / 60))
SECONDS=$((TOTAL_DURATION % 60))

# Print summary
echo ""
echo -e "${CYAN}╔════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║           TEST SUMMARY                     ║${NC}"
echo -e "${CYAN}╚════════════════════════════════════════════╝${NC}"
echo ""

echo -e "${YELLOW}⏱️  Total Duration: ${MINUTES}m ${SECONDS}s${NC}"
echo ""

# Passed tests
if [ ${#PASSED_TESTS[@]} -gt 0 ]; then
    echo -e "${GREEN}✅ Passed Tests (${#PASSED_TESTS[@]}/${TOTAL_TESTS}):${NC}"
    for test in "${PASSED_TESTS[@]}"; do
        echo -e "${GREEN}   ✓ $test${NC}"
    done
    echo ""
fi

# Failed tests
if [ ${#FAILED_TESTS[@]} -gt 0 ]; then
    echo -e "${RED}❌ Failed Tests (${#FAILED_TESTS[@]}/${TOTAL_TESTS}):${NC}"
    for test in "${FAILED_TESTS[@]}"; do
        echo -e "${RED}   ✗ $test${NC}"
    done
    echo ""
fi

# Final result
echo -e "${CYAN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
if [ ${#FAILED_TESTS[@]} -eq 0 ]; then
    echo -e "${GREEN}"
    echo "╔════════════════════════════════════════════╗"
    echo "║     🎉 ALL TESTS PASSED! 🎉               ║"
    echo "╚════════════════════════════════════════════╝"
    echo -e "${NC}"
    echo ""
    echo "The StoneScriptPHP framework and server are working correctly!"
    echo "You can now safely commit and push your changes."
    exit 0
else
    echo -e "${RED}"
    echo "╔════════════════════════════════════════════╗"
    echo "║     ⚠️  SOME TESTS FAILED ⚠️              ║"
    echo "╚════════════════════════════════════════════╝"
    echo -e "${NC}"
    echo ""
    echo "Please review the failed tests and fix the issues before committing."
    exit 1
fi
