# StoneScriptPHP
## A minimalistic backend framework for building APIs using PHP and PostgreSQL

---------------------------------------------------------------

## Setup 
To setup a PHP project for your backend api, use `composer create-project` command line
```
composer create-project progalaxyelabs/stone-script-php api
```
this will create a project in the `api` folder in your current folder and install the dependencies.

## Run
To run the php project, you can use the `serve.php` script from the project root
```
php serve.php
```
By default, this will use the port 9100.
You can check the server running by opening the browser and navigating to `http://localhost:9100`


## Workflow

Create all the database tables in individual .pssql files in the  `src/App/Database/postgres/tables/` folder

Create all the database queries as SQL functions in individual .pssql files in the  `src/App/Database/postgres/functions/` folder

if there is and seed data, create those as SQl scripts (insert statements) as .pssql files in the `src/App/Database/postgres/seeds/` folder


### create sql functions:

In pgadmin4, develop postgresql functions. test them and once working, save them as individual files under `src/App/Database/postgres/functions/` folder. ex: `src\App\Database\postgresql/functions/function_name.pssql`


### create php modal class for this sql function:

you can use the cli script to generate a PHP class for this sql function that will help in identifying the function arguments and return values

commands to run in terminal:


```shell

cd Framework/cli
php generate-model.php function_name.pssql

```

this will create a `FnFunctionName.php` in `src/App/Database/Functions` folder


This can be used to call the SQl function from PHP with proper arguments with reasonable typing that PHP allows. To see that in action, create an api route.


### create an api route:

```shell

# cd Framework/cli
php generate-route.php update-trophies

```

this will create a `UpdateTrophiesRoute.php` file in `Routes` folder


### create url to class route mapping:

in `src/App/Config/routes.php`, add a url-to-class route mapping

ex: for adding a post route, add the line in the `POST` section

```
return [
    ...
    'POST' => [
         ...
        '/update-trophies' => UpdateTrophiesRoute:class
        ...
    ]
    ...
];


```

### implement the route class's process function:

in `UpdateTrophiesRoute.php`, in `process` function, call the database function and return data


ex:


```php

$data = FnUpdatetrophyDetails::run(
    $user_trophy_id,
    $count
);

return new ApiResponse('ok', '', [
    'course' => $data
]);

```

## Roadmap - Upcoming Features

### 1. Database Drift Detection (Priority: HIGH)

**Feature:** `stones verify` command

Automatically detect when your database schema doesn't match your source code files. This catches drift that happens when:
- Someone manually modifies the database without creating a migration
- Migrations were applied in some environments but not others
- Database was modified outside the migration system

**Usage:**
```bash
stones verify

# Output:
Verifying database schema...
❌ Table 'users' missing column 'status' (present in tables/users.pssql)
❌ Function 'get_user_stats' exists in DB but not in functions/
✅ All other objects match

Status: DRIFT DETECTED
```

**What it checks:**
- Tables: Missing tables, extra tables, column differences (name, type, nullable, default)
- Functions: Missing functions, extra functions, signature changes
- Constraints: Foreign keys, unique constraints, check constraints

**Implementation:**
- Parses all `.pssql` files in `src/App/Database/postgresql/tables/` and `functions/`
- Queries PostgreSQL system catalogs (`information_schema`)
- Compares and reports differences
- CI/CD friendly with proper exit codes (0 = clean, 1 = drift detected)

### 2. Bash CLI Wrapper - "stones" Command

**Feature:** Simple, frictionless CLI for all framework operations

Zero-friction developer experience. Instead of typing long PHP commands, use simple bash commands.

**Setup:**
```bash
./cli.sh  # Makes 'stones' command available
```

**Commands:**

```bash
# Generate migration from detected changes
stones new migration

# Apply pending migrations
stones migrate

# Rollback last migration
stones rollback

# Check for database drift
stones verify

# Show migration status
stones status
```

**Developer Workflow:**

1. Edit `tables/users.pssql` directly (add `status` column)
2. Run `stones new migration` - auto-detects changes and generates timestamped migration file
3. Review the generated `migrations/20250107_153045_auto.sql`
4. Run `stones migrate` - applies the migration
5. Done!

**Key Features:**
- **Auto-detection:** No need to manually specify what changed
- **Timestamped migrations:** `migrations/YYYYMMDD_HHMMSS_auto.sql`
- **Up/Down migrations:** Automatic rollback support
- **Smart file naming:** Rename for clarity while preserving timestamp
- **CI/CD friendly:** Proper exit codes and non-interactive mode

**Philosophy:** Developers should type as little as possible. Edit files → generate migration → apply. Three simple steps.

### Migration System Architecture

**Source of Truth:** `tables/` and `functions/` folders contain the ideal state

**Change Management:** `migrations/` folder contains all actual changes applied to the database

**Tracking:** `schema_migrations` table tracks which migrations have been applied

**The "diff" validator** ensures the database matches the source of truth and catches any manual changes or drift.

This hybrid approach combines:
- **Database-first development** (edit .pssql files directly)
- **Version-controlled migrations** (explicit change management)
- **Drift detection** (validates actual state matches expected state)

Perfect for teams, multiple environments, and production deployments.

