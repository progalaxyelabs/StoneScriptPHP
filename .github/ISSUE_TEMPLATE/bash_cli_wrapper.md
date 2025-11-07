---
name: Bash CLI Wrapper (stones command)
about: Implement simple bash-based CLI for migration commands
title: 'Implement Bash CLI Wrapper for Migration Commands (stones)'
labels: enhancement, developer-experience
assignees: ''
---

## Feature: Bash CLI Wrapper for StoneScriptPHP

### Overview
Create a simple, bash-based CLI tool called `stones` that provides a frictionless developer experience for running migration and other framework commands.

### Philosophy
**Zero friction.** Developers should type as little as possible. No verbose commands, no unnecessary flags, just simple intuitive commands.

### Developer Experience

```bash
# One-time setup (optional)
./cli.sh  # Makes 'stones' command available in PATH

# Or just use directly
./stones new migration
./stones migrate
./stones rollback
./stones verify
./stones status
```

### Commands

#### `stones new migration`
- Auto-detects changes between source files (`tables/`, `functions/`) and database
- Runs diff/verify logic automatically
- Generates timestamped migration file with detected changes
- Creates both up and down migrations
- Output: `migrations/YYYYMMDD_HHMMSS_auto.sql`

**Example:**
```bash
$ stones new migration

Scanning for changes...
Found differences:
  - Table 'users': column 'status' added
  - Function 'get_user_stats': signature changed

✓ Generated: migrations/20250107_153045_auto.sql
✓ Generated: migrations/20250107_153045_auto.down.sql

Review and edit if needed, then run:
  stones migrate
```

#### `stones migrate`
- Applies all pending migrations in chronological order
- Updates `schema_migrations` tracking table
- Shows progress and results

**Example:**
```bash
$ stones migrate

Applying migrations...
  ✓ 20250107_153045_auto.sql
  ✓ 20250108_094521_auto.sql

✓ Applied 2 migrations
```

#### `stones rollback`
- Rolls back the last migration using `.down.sql` file
- Updates tracking table

**Example:**
```bash
$ stones rollback

Rolling back...
  ✓ Reverted 20250108_094521_auto.sql

✓ Rolled back 1 migration
```

#### `stones verify`
- Checks for database drift (schema differences)
- Returns exit code 0 if clean, 1 if drift detected (CI/CD friendly)
- Detailed diff output

**Example:**
```bash
$ stones verify

Verifying database schema...
❌ Table 'users' missing column 'status' (present in tables/users.pssql)
✅ All functions match

Status: DRIFT DETECTED (1 issue)
```

#### `stones status`
- Shows applied migrations
- Shows pending migrations
- Shows current database state

**Example:**
```bash
$ stones status

Applied migrations:
  ✓ 20250107_093045_auto.sql (2 days ago)
  ✓ 20250108_141523_auto.sql (1 day ago)

Pending migrations:
  • 20250109_160000_auto.sql

Database: ✓ In sync with applied migrations
```

### Implementation Files

#### 1. `stones` (main bash script)
Location: Project root

```bash
#!/bin/bash
# StoneScriptPHP CLI

case "$1" in
  new)
    case "$2" in
      migration)
        php Framework/cli/migrate.php generate
        ;;
      *)
        echo "Usage: stones new migration"
        ;;
    esac
    ;;
  migrate)
    php Framework/cli/migrate.php up
    ;;
  rollback)
    php Framework/cli/migrate.php down
    ;;
  verify)
    php Framework/cli/migrate.php verify
    ;;
  status)
    php Framework/cli/migrate.php status
    ;;
  *)
    # Show help
    ;;
esac
```

#### 2. `cli.sh` (setup helper)
Location: Project root

Makes `stones` executable and optionally adds to PATH for convenience.

#### 3. `Framework/cli/migrate.php`
The actual PHP implementation that handles:
- `generate` - Auto-generate migration from diff
- `up` - Apply migrations
- `down` - Rollback migrations
- `verify` - Check for drift
- `status` - Show migration status

### Key Features

**1. Auto-Detection**
- When `stones new migration` runs, it automatically detects changes
- No need to specify what changed
- Generates appropriate SQL based on detected differences

**2. Smart Migration Files**
- Timestamped: `YYYYMMDD_HHMMSS_auto.sql`
- Developer can rename for clarity: `20250107_153045_add_user_status.sql`
- Timestamp preserved for ordering

**3. Up/Down Migrations**
- Every migration generates both `.sql` and `.down.sql`
- Rollback support out of the box

**4. CI/CD Friendly**
- Proper exit codes
- Machine-readable output option
- Non-interactive mode

### Technical Requirements

- [ ] Create `stones` bash script
- [ ] Create `cli.sh` setup helper
- [ ] Make scripts executable by default
- [ ] Integrate with `Framework/cli/migrate.php`
- [ ] Add help documentation (`stones --help`)
- [ ] Support both `./stones` and global `stones` (in PATH)
- [ ] Color-coded output (optional but nice)
- [ ] Works on Linux, macOS, WSL

### Future Enhancements (Not in Scope)

- `stones new route <name>` - Generate route class
- `stones new model <function>` - Generate model from SQL function
- `stones seed` - Run seed files
- `stones fresh` - Drop all + reapply migrations (dev only)
- `stones db:shell` - Open PostgreSQL shell
- `stones serve` - Start development server

### Dependencies

Depends on:
- Issue: "Implement Database Drift Detection (migrate verify command)" - The verify/diff logic must be implemented first
- `Framework/Migrations.php` - Core migration logic
- `Framework/cli/migrate.php` - PHP CLI implementation

### Success Criteria

- [ ] Developer can run `./stones new migration` and it generates a migration
- [ ] Developer can run `./stones migrate` and it applies migrations
- [ ] Developer can run `./stones rollback` and it reverts last migration
- [ ] Developer can run `./stones verify` and it checks for drift
- [ ] Developer can run `./stones status` and it shows migration state
- [ ] All commands have clear, helpful output
- [ ] Error messages are actionable
- [ ] Works without requiring global installation

### Notes

This is about **developer experience**. The goal is to make migrations so easy that developers never skip them. Edit file → generate migration → apply. Three simple steps.
