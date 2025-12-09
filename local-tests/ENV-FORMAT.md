# .env File Format Requirements

## Important: Use Semicolons for Comments

StoneScriptPHP uses `parse_ini_file()` to parse `.env` files. This function has specific requirements for comment syntax:

### ✅ Correct - Use Semicolons
```ini
; This is a valid comment
APP_NAME=StoneScriptPHP
APP_ENV=development

; Database configuration
DATABASE_HOST=localhost
DATABASE_PORT=5432
```

### ❌ Incorrect - Hash Symbols Don't Work
```ini
# This will NOT work with parse_ini_file()
APP_NAME=StoneScriptPHP
APP_ENV=development

# This comment will cause parsing errors
DATABASE_HOST=localhost
```

## Best Practice: Use CLI to Generate .env

Always use the framework's CLI command to generate `.env` files:

```bash
php stone generate env
```

This ensures:
- ✅ Correct format with semicolon comments
- ✅ All required variables are included
- ✅ Proper structure for `parse_ini_file()`
- ✅ Framework defaults are set

## Modifying .env Files

### In Shell Scripts
Use `sed` to update specific values:
```bash
php stone generate env --force
sed -i 's/^APP_ENV=.*/APP_ENV=production/' .env
sed -i 's/^DATABASE_HOST=.*/DATABASE_HOST=db.example.com/' .env
```

### In Dockerfiles
```dockerfile
RUN php stone generate env --force && \
    sed -i 's/^APP_ENV=.*/APP_ENV=production/' .env && \
    sed -i 's/^DATABASE_HOST=.*/DATABASE_HOST=postgres/' .env
```

### Manual Editing
If you must create `.env` manually, use semicolons:
```ini
; Application Settings
APP_NAME=MyApp
APP_ENV=production

; Database Settings
DATABASE_HOST=localhost
DATABASE_PORT=5432
DATABASE_DBNAME=mydb
```

## Why This Matters

PHP's `parse_ini_file()` follows INI file format rules:
- `;` is the standard comment character
- `#` may work in some PHP versions but is **not reliable**
- Using `#` can cause parsing failures in production

## Test Files Compliance

All test scripts in this suite follow this requirement:

| Test | Method |
|------|--------|
| Test 1 | Uses `php stone generate env` ✅ |
| Test 2 | Uses `php stone generate env` in Dockerfile ✅ |
| Test 3 | Uses `php stone generate env` in Dockerfile ✅ |
| Test 4 | Uses environment variables from docker-compose ✅ |
| Test 5 | Uses environment variables from docker-compose ✅ |
| Test 6 | Uses environment variables from docker-compose ✅ |

## References

- PHP Manual: [parse_ini_file()](https://www.php.net/manual/en/function.parse-ini-file.php)
- INI Format: [Wikipedia](https://en.wikipedia.org/wiki/INI_file)
