# StoneScriptPHP Integration Tests

Validates that all code examples in README.md and CLI-USAGE.md actually work.

## Run Tests

```bash
cd integration-tests
chmod +x run-tests.sh
./run-tests.sh
```

## What Gets Tested

1. ✅ Installation via composer create-project
2. ✅ Project structure matches documentation
3. ✅ All CLI commands work as documented
4. ✅ Route generation
5. ✅ Model generation
6. ✅ Migration commands
7. ✅ OAuth setup

## Environment

- PHP 8.2 container
- PostgreSQL 16 container
- Isolated from host machine
