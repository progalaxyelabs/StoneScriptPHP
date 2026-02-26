<?php
/**
 * Tenant Management CLI
 *
 * Commands:
 * - php stone tenant:create <name> <slug> [--email=admin@example.com]
 * - php stone tenant:list
 * - php stone tenant:status <slug>
 * - php stone tenant:migrate <slug>
 * - php stone tenant:seed <slug>
 * - php stone tenant:suspend <slug>
 * - php stone tenant:activate <slug>
 * - php stone tenant:delete <slug> [--drop-db]
 */

require_once __DIR__ . '/generate-common.php';

use StoneScriptPHP\Tenancy\TenantProvisioner;
use StoneScriptPHP\Tenancy\Tenant;

// Parse subcommand
$subCommand = $args[0] ?? 'help';
$subArgs = array_slice($args, 1);

// Load database configuration
$configFile = ROOT_PATH . 'config/database.php';
if (!file_exists($configFile)) {
    echo Color::red("Error: Database configuration not found at {$configFile}\n");
    echo "Please create config/database.php with your database settings.\n";
    exit(1);
}

$dbConfig = require $configFile;

// Connect to central auth database
try {
    $dsn = sprintf(
        '%s:host=%s;port=%d;dbname=%s',
        $dbConfig['driver'] ?? 'pgsql',
        $dbConfig['host'] ?? 'localhost',
        $dbConfig['port'] ?? 5432,
        $dbConfig['database'] ?? 'auth'
    );

    $authDb = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo Color::red("Error: Failed to connect to database - {$e->getMessage()}\n");
    exit(1);
}

// Tenant provisioner configuration
$provisionerConfig = [
    'strategy' => 'per_tenant_db',
    'tenant_table' => 'tenants',
    'db_prefix' => 'tenant_',
    'db_config' => $dbConfig,
    'migrations_dir' => ROOT_PATH . 'database/migrations/tenant',
    'seeds_dir' => ROOT_PATH . 'database/seeds/tenant',
    'auto_seed' => true
];

$provisioner = new TenantProvisioner($authDb, $provisionerConfig);

// Route to subcommand
match ($subCommand) {
    'create' => tenantCreate($provisioner, $subArgs),
    'list' => tenantList($authDb, $provisionerConfig),
    'status' => tenantStatus($authDb, $provisioner, $subArgs, $provisionerConfig),
    'migrate' => tenantMigrate($provisioner, $subArgs),
    'seed' => tenantSeed($provisioner, $subArgs),
    'suspend' => tenantSuspend($provisioner, $subArgs),
    'activate' => tenantActivate($provisioner, $subArgs),
    'delete' => tenantDelete($provisioner, $subArgs),
    'help' => tenantHelp(),
    default => tenantHelp()
};

/**
 * Create new tenant
 */
function tenantCreate(TenantProvisioner $provisioner, array $args): void
{
    if (count($args) < 2) {
        echo Color::red("Error: Missing required arguments\n");
        echo "Usage: php stone tenant:create <name> <slug> [--email=admin@example.com]\n";
        exit(1);
    }

    $name = $args[0];
    $slug = $args[1];

    // Parse options
    $email = null;
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--email=')) {
            $email = substr($arg, 8);
        }
    }

    echo Color::blue("Creating tenant: {$name} ({$slug})\n");

    try {
        $tenant = $provisioner->createTenant([
            'name' => $name,
            'slug' => $slug,
            'email' => $email
        ]);

        echo Color::green("✓ Tenant created successfully!\n");
        echo "  ID: {$tenant->id}\n";
        echo "  UUID: {$tenant->uuid}\n";
        echo "  Slug: {$tenant->slug}\n";
        echo "  Database: {$tenant->dbName}\n";

    } catch (Exception $e) {
        echo Color::red("✗ Failed to create tenant: {$e->getMessage()}\n");
        exit(1);
    }
}

/**
 * List all tenants
 */
function tenantList(PDO $authDb, array $config): void
{
    try {
        $table = $config['tenant_table'];
        $stmt = $authDb->query("SELECT id, uuid, slug, name, db_name, status, created_at FROM {$table} ORDER BY created_at DESC");
        $tenants = $stmt->fetchAll();

        if (empty($tenants)) {
            echo Color::yellow("No tenants found.\n");
            return;
        }

        echo Color::blue("Tenants:\n");
        echo str_repeat('-', 100) . "\n";
        printf("%-5s %-37s %-20s %-25s %-30s %-10s\n", 'ID', 'UUID', 'Slug', 'Name', 'Database', 'Status');
        echo str_repeat('-', 100) . "\n";

        foreach ($tenants as $tenant) {
            printf(
                "%-5s %-37s %-20s %-25s %-30s %s\n",
                $tenant['id'],
                $tenant['uuid'] ?? 'N/A',
                $tenant['slug'],
                substr($tenant['name'], 0, 25),
                $tenant['db_name'],
                $tenant['status'] === 'active' ? Color::green($tenant['status']) : Color::yellow($tenant['status'])
            );
        }

        echo str_repeat('-', 100) . "\n";
        echo "Total: " . count($tenants) . " tenant(s)\n";

    } catch (PDOException $e) {
        echo Color::red("Error: {$e->getMessage()}\n");
        exit(1);
    }
}

/**
 * Show tenant status and details
 */
function tenantStatus(PDO $authDb, TenantProvisioner $provisioner, array $args, array $config): void
{
    if (empty($args[0])) {
        echo Color::red("Error: Missing tenant slug\n");
        echo "Usage: php stone tenant:status <slug>\n";
        exit(1);
    }

    $slug = $args[0];

    try {
        $table = $config['tenant_table'];
        $stmt = $authDb->prepare("SELECT * FROM {$table} WHERE slug = ?");
        $stmt->execute([$slug]);
        $tenant = $stmt->fetch();

        if (!$tenant) {
            echo Color::red("Error: Tenant '{$slug}' not found\n");
            exit(1);
        }

        echo Color::blue("Tenant Details:\n");
        echo str_repeat('-', 60) . "\n";
        echo "  ID:             {$tenant['id']}\n";
        echo "  UUID:           {$tenant['uuid']}\n";
        echo "  Slug:           {$tenant['slug']}\n";
        echo "  Name:           {$tenant['name']}\n";
        echo "  Database:       {$tenant['db_name']}\n";
        echo "  Status:         " . ($tenant['status'] === 'active' ? Color::green($tenant['status']) : Color::yellow($tenant['status'])) . "\n";
        echo "  Email:          " . ($tenant['email'] ?? 'N/A') . "\n";
        echo "  Created:        {$tenant['created_at']}\n";
        echo "  Updated:        {$tenant['updated_at']}\n";

        if ($tenant['db_created_at']) {
            echo "  DB Created:     {$tenant['db_created_at']}\n";
        }

        echo str_repeat('-', 60) . "\n";

    } catch (PDOException $e) {
        echo Color::red("Error: {$e->getMessage()}\n");
        exit(1);
    }
}

/**
 * Run migrations on tenant database
 */
function tenantMigrate(TenantProvisioner $provisioner, array $args): void
{
    if (empty($args[0])) {
        echo Color::red("Error: Missing tenant slug\n");
        echo "Usage: php stone tenant:migrate <slug>\n";
        exit(1);
    }

    $slug = $args[0];
    echo Color::blue("Running migrations for tenant: {$slug}\n");

    // Get tenant by slug to find database name
    // This is a simplified version - in production you'd look up the tenant
    $dbName = "tenant_" . str_replace('-', '', $slug);

    try {
        $success = $provisioner->runMigrations($dbName);

        if ($success) {
            echo Color::green("✓ Migrations completed successfully!\n");
        } else {
            echo Color::yellow("⚠ Migrations completed with warnings.\n");
        }

    } catch (Exception $e) {
        echo Color::red("✗ Migration failed: {$e->getMessage()}\n");
        exit(1);
    }
}

/**
 * Seed tenant database
 */
function tenantSeed(TenantProvisioner $provisioner, array $args): void
{
    if (empty($args[0])) {
        echo Color::red("Error: Missing tenant slug\n");
        echo "Usage: php stone tenant:seed <slug>\n";
        exit(1);
    }

    $slug = $args[0];
    echo Color::blue("Seeding database for tenant: {$slug}\n");

    $dbName = "tenant_" . str_replace('-', '', $slug);

    try {
        $success = $provisioner->seedDatabase($dbName);

        if ($success) {
            echo Color::green("✓ Database seeded successfully!\n");
        } else {
            echo Color::yellow("⚠ Seeding completed with warnings.\n");
        }

    } catch (Exception $e) {
        echo Color::red("✗ Seeding failed: {$e->getMessage()}\n");
        exit(1);
    }
}

/**
 * Suspend tenant
 */
function tenantSuspend(TenantProvisioner $provisioner, array $args): void
{
    if (empty($args[0])) {
        echo Color::red("Error: Missing tenant slug\n");
        echo "Usage: php stone tenant:suspend <slug>\n";
        exit(1);
    }

    $slug = $args[0];
    echo Color::yellow("Suspending tenant: {$slug}\n");

    try {
        $success = $provisioner->suspendTenant($slug);

        if ($success) {
            echo Color::green("✓ Tenant suspended successfully!\n");
        } else {
            echo Color::red("✗ Failed to suspend tenant.\n");
            exit(1);
        }

    } catch (Exception $e) {
        echo Color::red("✗ Error: {$e->getMessage()}\n");
        exit(1);
    }
}

/**
 * Activate tenant
 */
function tenantActivate(TenantProvisioner $provisioner, array $args): void
{
    if (empty($args[0])) {
        echo Color::red("Error: Missing tenant slug\n");
        echo "Usage: php stone tenant:activate <slug>\n";
        exit(1);
    }

    $slug = $args[0];
    echo Color::blue("Activating tenant: {$slug}\n");

    try {
        $success = $provisioner->activateTenant($slug);

        if ($success) {
            echo Color::green("✓ Tenant activated successfully!\n");
        } else {
            echo Color::red("✗ Failed to activate tenant.\n");
            exit(1);
        }

    } catch (Exception $e) {
        echo Color::red("✗ Error: {$e->getMessage()}\n");
        exit(1);
    }
}

/**
 * Delete tenant
 */
function tenantDelete(TenantProvisioner $provisioner, array $args): void
{
    if (empty($args[0])) {
        echo Color::red("Error: Missing tenant slug\n");
        echo "Usage: php stone tenant:delete <slug> [--drop-db]\n";
        exit(1);
    }

    $slug = $args[0];
    $dropDb = in_array('--drop-db', $args);

    echo Color::yellow("Warning: This will mark the tenant as deleted.\n");
    if ($dropDb) {
        echo Color::red("⚠ The --drop-db flag will PERMANENTLY DELETE the database!\n");
    }

    echo "Are you sure you want to delete tenant '{$slug}'? (yes/no): ";
    $confirmation = trim(fgets(STDIN));

    if (strtolower($confirmation) !== 'yes') {
        echo "Operation cancelled.\n";
        exit(0);
    }

    try {
        $success = $provisioner->deleteTenant($slug);

        if ($success) {
            echo Color::green("✓ Tenant marked as deleted!\n");

            if ($dropDb) {
                $dbName = "tenant_" . str_replace('-', '', $slug);
                echo Color::yellow("Dropping database: {$dbName}\n");

                $provisioner->dropDatabase($dbName);
                echo Color::green("✓ Database dropped successfully!\n");
            }
        } else {
            echo Color::red("✗ Failed to delete tenant.\n");
            exit(1);
        }

    } catch (Exception $e) {
        echo Color::red("✗ Error: {$e->getMessage()}\n");
        exit(1);
    }
}

/**
 * Show help
 */
function tenantHelp(): void
{
    echo Color::blue("Tenant Management Commands:\n\n");
    echo "  " . Color::green("tenant:create") . " <name> <slug> [--email=admin@example.com]\n";
    echo "    Create a new tenant with database provisioning\n\n";
    echo "  " . Color::green("tenant:list") . "\n";
    echo "    List all tenants\n\n";
    echo "  " . Color::green("tenant:status") . " <slug>\n";
    echo "    Show detailed tenant information\n\n";
    echo "  " . Color::green("tenant:migrate") . " <slug>\n";
    echo "    Run database migrations for tenant\n\n";
    echo "  " . Color::green("tenant:seed") . " <slug>\n";
    echo "    Seed tenant database with initial data\n\n";
    echo "  " . Color::green("tenant:suspend") . " <slug>\n";
    echo "    Suspend tenant (disable access)\n\n";
    echo "  " . Color::green("tenant:activate") . " <slug>\n";
    echo "    Activate tenant (enable access)\n\n";
    echo "  " . Color::green("tenant:delete") . " <slug> [--drop-db]\n";
    echo "    Delete tenant (optionally drop database with --drop-db)\n\n";
    echo "Examples:\n";
    echo "  php stone tenant:create \"Acme Corp\" acme --email=admin@acme.com\n";
    echo "  php stone tenant:list\n";
    echo "  php stone tenant:status acme\n";
    echo "  php stone tenant:migrate acme\n";
}
