# Routing System Tests

This directory contains tests for the routing infrastructure using PHPUnit.

## Test Structure

- `Unit/` - Unit tests for individual routing components
  - `RouterTest.php` - Tests for core Router class functionality

- `Feature/` - Feature tests for end-to-end routing scenarios
  - `DynamicRoutingTest.php` - Tests for advanced routing features

## Running Tests

```bash
# Install dependencies (including PHPUnit)
composer install

# Run all tests
./vendor/bin/phpunit

# Run only unit tests
./vendor/bin/phpunit --testsuite Unit

# Run only feature tests
./vendor/bin/phpunit --testsuite Feature

# Run tests with coverage report (requires Xdebug)
./vendor/bin/phpunit --coverage-html coverage
```

## Test-Driven Development Approach

1. **Write failing tests first** - Define expected behavior before implementation
2. **Implement minimal code** - Write just enough code to make tests pass
3. **Refactor** - Improve code while keeping tests green
4. **Repeat** - Continue the cycle for each new feature

## Adding New Tests

When adding new routing features:

1. Create test cases in the appropriate test file
2. Run tests to verify they fail
3. Implement the feature
4. Run tests again to verify they pass
5. Refactor and ensure tests remain green

## Current Test Status

All tests are currently marked as incomplete and serve as a roadmap for routing system development. As features are implemented, tests will be updated accordingly.
