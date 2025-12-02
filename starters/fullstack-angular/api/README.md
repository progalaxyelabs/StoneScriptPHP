# API Service

This is the StoneScriptPHP backend for the fullstack application.

## Structure

This follows the same structure as the `basic-api` starter template. Refer to:
- `/starters/basic-api/` for complete example files
- Main StoneScriptPHP documentation at https://stonescriptphp.org

## Quick Setup

1. Copy all files from `starters/basic-api/src/` to this directory
2. Update `src/App/Config/routes.php` with your routes
3. Create database tables in `src/postgresql/tables/`
4. Create SQL functions in `src/postgresql/functions/`
5. Generate models: `php stone generate model <function>.pgsql`
6. Generate routes: `php stone generate route <name>`

## Key Files to Copy

From `starters/basic-api/`:
- `src/App/Routes/` - Route handlers
- `src/App/Models/` - Database models
- `src/App/Config/routes.php` - Route configuration
- `src/postgresql/` - Database files

## Commands

```bash
php stone setup              # Setup project
php stone serve              # Start dev server
php stone generate route <name>   # Generate route
php stone generate model <file>   # Generate model
php stone migrate verify     # Verify database
php stone test               # Run tests
```

## Docker

When running in Docker Compose (from parent directory):

```bash
docker-compose exec api php stone migrate verify
docker-compose logs -f api
```
