# CLI Script Management System

**[← Back to README](../README.md)** | **[📑 Documentation Index](INDEX.md)** | **[🏗️ Architecture](../HLD.md)**

---

This project uses a centralized CLI script management system. All CLI scripts are located in `Framework/cli/` and are executed through a main dispatcher script.

## Usage

From the project root, use the `stone` script to run any CLI command:

```bash
php stone <command> [arguments...]
```

## Available Commands

### Project Setup
Interactive setup wizard (runs automatically after `composer create-project`):

```bash
php stone setup
```

This command:
- Prompts you to choose a starter template (Basic API, Microservice, SaaS Boilerplate)
- Scaffolds project structure from selected template
- Configures database connection
- Generates JWT keypair
- Creates .env configuration file
- Runs initial database migrations

**Examples:**
```bash
php stone new my-api                           # Create new API project
php stone new my-api --template=api --git      # Create with git init
php stone new my-service --template=microservice  # Create microservice project
php stone new my-saas --template=saas-boilerplate # Create SaaS project
```

This command creates a complete project structure with:
- Framework core files
- Project scaffolding (Routes, DTOs, Models, etc.)
- Configuration files (.env.example, .gitignore, phpunit.xml)
- composer.json with dependencies
- Optional git initialization
- Optional interactive setup for database and JWT keys

### Route Generator
Generate a new route handler class:

```bash
php generate route <route-name>
```

**Example:**
```bash
php generate route user-login
# Creates: Routes/UserLoginRoute.php
```

### Environment Configuration
Generate or update .env file from schema:

```bash
php generate env [--force] [--example]
```

**Options:**
- `--force`: Overwrite existing values with defaults
- `--example`: Generate .env.example instead of .env

**Examples:**
```bash
php generate env                # Generate/update .env
php generate env --force        # Regenerate with defaults
php generate env --example      # Generate .env.example
```

### Model Generator
Generate PHP model classes from PostgreSQL function definitions:

```bash
php generate model <filename.pssql>
```

**Example:**
```bash
php generate model get_user.pssql
# Generates model from postgresql/functions/get_user.pssql
```

### Database Migrations
Manage database migrations:

```bash
php generate migrate <command>
```

**Commands:**
- `verify` - Check for database drift
- `status` - Show migration status (coming soon)
- `up` - Apply pending migrations (coming soon)
- `down` - Rollback last migration (coming soon)
- `generate` - Generate migration from changes (coming soon)

**Example:**
```bash
php generate migrate verify
```

### Database Administration
Execute database queries and SQL files:

```bash
php generate dba <command> [arguments...]
```

**Commands:**
- `query <sql>` - Execute a SQL query
- `file <filename>` - Execute SQL from a file in the postgresql directory

**Examples:**
```bash
php generate dba query "SELECT version()"
php generate dba file schema/create_tables.sql
```

## Getting Help

For general help:
```bash
php generate help
```

For command-specific help:
```bash
php generate <command> --help
```

## Script Locations

All CLI script implementations are located in:
```
Framework/cli/
├── generate-route.php    # Route generator
├── generate-env.php      # Environment configuration generator
├── generate-model.php    # Model generator from PostgreSQL functions
├── migrate.php           # Migration management
└── dba.php              # Database administration utilities
```

The main dispatcher is located at:
```
generate                  # Main CLI dispatcher (project root)
```

## Architecture

The CLI system uses a dispatcher pattern:
1. The `stone` script at the root receives all commands
2. It maps the command name to the appropriate script in `Framework/cli/`
3. Arguments are passed through to the target script
4. The script executes in the correct working directory with proper path resolution

All scripts use `ROOT_PATH` for path resolution, ensuring they work correctly regardless of where they're called from.

---

## Related Documentation

- **[← Back to README](../README.md)** - Main project overview
- **[📑 Documentation Index](INDEX.md)** - Complete documentation navigation
- **[🚀 Getting Started Guide](getting-started.md)** - Complete tutorial
- **[🏗️ High Level Design](../HLD.md)** - System architecture

---

**[StoneScriptPHP](https://stonescriptphp.org)** - Modern PHP framework for building APIs
