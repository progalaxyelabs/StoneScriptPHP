<?php

namespace StoneScriptPHP\Tenancy;

use PDO;
use PDOException;

/**
 * Tenant Provisioner
 *
 * Handles tenant database provisioning for per-tenant database isolation strategy:
 * - Create tenant record in central auth database
 * - Create dedicated PostgreSQL database for tenant
 * - Run migrations on tenant database
 * - Seed initial data
 * - Manage tenant lifecycle (suspend, delete, etc.)
 *
 * Usage:
 *   $provisioner = new TenantProvisioner($authDb, $config);
 *   $tenant = $provisioner->createTenant([
 *       'name' => 'Acme Corp',
 *       'slug' => 'acme',
 *       'email' => 'admin@acme.com'
 *   ]);
 */
class TenantProvisioner
{
    /**
     * Create a new TenantProvisioner
     *
     * @param PDO $authDb Central auth database connection
     * @param array $config Configuration array
     */
    public function __construct(
        private PDO $authDb,
        private array $config
    ) {}

    /**
     * Create a new tenant
     *
     * Full provisioning process:
     * 1. Validate tenant data
     * 2. Create tenant record in auth database
     * 3. Create dedicated database (if per-tenant strategy)
     * 4. Run migrations
     * 5. Seed initial data
     * 6. Update tenant status to active
     *
     * @param array $data Tenant data (name, slug, email, etc.)
     * @return Tenant Created tenant object
     * @throws \Exception If provisioning fails
     */
    public function createTenant(array $data): Tenant
    {
        try {
            $this->authDb->beginTransaction();

            // 1. Validate tenant data
            $this->validateTenantData($data);

            // 2. Create tenant record in auth database
            $tenant = $this->createTenantRecord($data);

            log_info("TenantProvisioner: Created tenant record", ['tenant_id' => $tenant->id]);

            // 3. Provision database based on strategy
            $strategy = $this->config['strategy'] ?? 'per_tenant_db';

            if ($strategy === 'per_tenant_db') {
                // Create dedicated database
                $this->createDatabase($tenant->dbName);
                log_info("TenantProvisioner: Created database {$tenant->dbName}");

                // Run migrations
                $this->runMigrations($tenant->dbName);
                log_info("TenantProvisioner: Ran migrations on {$tenant->dbName}");

                // Seed database
                if ($this->config['auto_seed'] ?? true) {
                    $this->seedDatabase($tenant->dbName);
                    log_info("TenantProvisioner: Seeded database {$tenant->dbName}");
                }

                // Update tenant status
                $this->updateTenantStatus($tenant->uuid ?? (string) $tenant->id, 'active', new \DateTime());
            }

            $this->authDb->commit();

            log_info("TenantProvisioner: Successfully provisioned tenant", [
                'tenant_id' => $tenant->id,
                'slug' => $tenant->slug
            ]);

            return $tenant;

        } catch (\Exception $e) {
            $this->authDb->rollBack();
            log_error("TenantProvisioner: Failed to create tenant - {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Create tenant record in auth database
     *
     * @param array $data
     * @return Tenant
     * @throws PDOException
     */
    private function createTenantRecord(array $data): Tenant
    {
        $table = $this->config['tenant_table'] ?? 'tenants';
        $uuid = $data['uuid'] ?? $this->generateUuid();
        $slug = $data['slug'];
        $dbName = $data['db_name'] ?? $this->generateDbName($uuid);

        $sql = "INSERT INTO {$table} (uuid, slug, name, db_name, email, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                RETURNING id, uuid, slug, db_name";

        $stmt = $this->authDb->prepare($sql);
        $stmt->execute([
            $uuid,
            $slug,
            $data['name'],
            $dbName,
            $data['email'] ?? null,
            'pending'
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return Tenant::fromDatabase($row);
    }

    /**
     * Create PostgreSQL database for tenant
     *
     * @param string $dbName Database name
     * @return bool Success status
     * @throws \Exception If database creation fails
     */
    public function createDatabase(string $dbName): bool
    {
        try {
            // Connect to postgres database to create new database
            $config = $this->config['db_config'];
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=postgres',
                $config['host'] ?? 'localhost',
                $config['port'] ?? 5432
            );

            $adminDb = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Check if database already exists
            $stmt = $adminDb->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $stmt->execute([$dbName]);

            if ($stmt->fetch()) {
                log_info("TenantProvisioner: Database {$dbName} already exists");
                return true;
            }

            // Create the database
            // Note: Can't use prepared statements for CREATE DATABASE
            $quotedDbName = $adminDb->quote($dbName);
            $adminDb->exec("CREATE DATABASE {$quotedDbName}");

            log_info("TenantProvisioner: Created database {$dbName}");

            return true;

        } catch (PDOException $e) {
            log_error("TenantProvisioner: Failed to create database {$dbName} - {$e->getMessage()}");
            throw new \Exception("Failed to create database: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Run migrations on tenant database
     *
     * Executes migration files from configured migrations directory.
     * This is a basic implementation - in production you'd use a proper migration tool.
     *
     * @param string $dbName Tenant database name
     * @return bool Success status
     */
    public function runMigrations(string $dbName): bool
    {
        try {
            $migrationsDir = $this->config['migrations_dir'] ?? null;

            if (!$migrationsDir || !is_dir($migrationsDir)) {
                log_warning("TenantProvisioner: No migrations directory configured or found");
                return true;
            }

            // Connect to tenant database
            $tenantDb = $this->getTenantConnection($dbName);

            // Get migration files
            $files = glob($migrationsDir . '/*.sql');
            sort($files);

            foreach ($files as $file) {
                $sql = file_get_contents($file);
                $tenantDb->exec($sql);
                log_debug("TenantProvisioner: Executed migration " . basename($file));
            }

            log_info("TenantProvisioner: Completed migrations for {$dbName}");

            return true;

        } catch (\Exception $e) {
            log_error("TenantProvisioner: Migration failed for {$dbName} - {$e->getMessage()}");
            throw new \Exception("Migration failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Seed tenant database with initial data
     *
     * @param string $dbName Tenant database name
     * @return bool Success status
     */
    public function seedDatabase(string $dbName): bool
    {
        try {
            $seedsDir = $this->config['seeds_dir'] ?? null;

            if (!$seedsDir || !is_dir($seedsDir)) {
                log_debug("TenantProvisioner: No seeds directory configured");
                return true;
            }

            // Connect to tenant database
            $tenantDb = $this->getTenantConnection($dbName);

            // Get seed files
            $files = glob($seedsDir . '/*.sql');
            sort($files);

            foreach ($files as $file) {
                $sql = file_get_contents($file);
                $tenantDb->exec($sql);
                log_debug("TenantProvisioner: Executed seed " . basename($file));
            }

            log_info("TenantProvisioner: Completed seeding for {$dbName}");

            return true;

        } catch (\Exception $e) {
            log_error("TenantProvisioner: Seeding failed for {$dbName} - {$e->getMessage()}");
            throw new \Exception("Seeding failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Suspend tenant (disable access)
     *
     * @param string $uuid Tenant UUID or ID
     * @return bool
     */
    public function suspendTenant(string $uuid): bool
    {
        return $this->updateTenantStatus($uuid, 'suspended');
    }

    /**
     * Activate tenant (enable access)
     *
     * @param string $uuid Tenant UUID or ID
     * @return bool
     */
    public function activateTenant(string $uuid): bool
    {
        return $this->updateTenantStatus($uuid, 'active');
    }

    /**
     * Delete tenant (soft delete - mark as deleted)
     *
     * Note: This does NOT drop the database. Use dropDatabase() separately if needed.
     *
     * @param string $uuid Tenant UUID or ID
     * @return bool
     */
    public function deleteTenant(string $uuid): bool
    {
        return $this->updateTenantStatus($uuid, 'deleted');
    }

    /**
     * Drop tenant database (DESTRUCTIVE - cannot be undone)
     *
     * @param string $dbName Database name
     * @return bool
     * @throws \Exception If database drop fails
     */
    public function dropDatabase(string $dbName): bool
    {
        try {
            $config = $this->config['db_config'];
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=postgres',
                $config['host'] ?? 'localhost',
                $config['port'] ?? 5432
            );

            $adminDb = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Terminate all connections to the database
            $adminDb->exec("
                SELECT pg_terminate_backend(pg_stat_activity.pid)
                FROM pg_stat_activity
                WHERE pg_stat_activity.datname = '{$dbName}'
                AND pid <> pg_backend_pid()
            ");

            // Drop the database
            $quotedDbName = $adminDb->quote($dbName);
            $adminDb->exec("DROP DATABASE IF EXISTS {$quotedDbName}");

            log_warning("TenantProvisioner: Dropped database {$dbName}");

            return true;

        } catch (PDOException $e) {
            log_error("TenantProvisioner: Failed to drop database {$dbName} - {$e->getMessage()}");
            throw new \Exception("Failed to drop database: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Update tenant status in auth database
     *
     * @param string $uuid Tenant UUID or ID
     * @param string $status New status
     * @param \DateTime|null $dbCreatedAt Database creation timestamp
     * @return bool
     */
    private function updateTenantStatus(string $uuid, string $status, ?\DateTime $dbCreatedAt = null): bool
    {
        try {
            $table = $this->config['tenant_table'] ?? 'tenants';

            $sql = "UPDATE {$table} SET status = ?, updated_at = NOW()";
            $params = [$status];

            if ($dbCreatedAt) {
                $sql .= ", db_created_at = ?";
                $params[] = $dbCreatedAt->format('Y-m-d H:i:s');
            }

            $sql .= " WHERE uuid = ? OR id::text = ?";
            $params[] = $uuid;
            $params[] = $uuid;

            $stmt = $this->authDb->prepare($sql);
            $stmt->execute($params);

            return true;

        } catch (PDOException $e) {
            log_error("TenantProvisioner: Failed to update tenant status - {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get connection to tenant database
     *
     * @param string $dbName
     * @return PDO
     */
    private function getTenantConnection(string $dbName): PDO
    {
        $config = $this->config['db_config'];
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $config['host'] ?? 'localhost',
            $config['port'] ?? 5432,
            $dbName
        );

        return new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    }

    /**
     * Validate tenant data
     *
     * @param array $data
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateTenantData(array $data): void
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('Tenant name is required');
        }

        if (empty($data['slug'])) {
            throw new \InvalidArgumentException('Tenant slug is required');
        }

        // Validate slug format (alphanumeric and hyphens only)
        if (!preg_match('/^[a-z0-9-]+$/', $data['slug'])) {
            throw new \InvalidArgumentException('Tenant slug must contain only lowercase letters, numbers, and hyphens');
        }
    }

    /**
     * Generate UUID v4
     *
     * @return string
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Generate database name from UUID
     *
     * Default format: {prefix}{uuid_without_hyphens}
     *
     * @param string $uuid
     * @return string
     */
    private function generateDbName(string $uuid): string
    {
        $prefix = $this->config['db_prefix'] ?? 'tenant_';
        return $prefix . str_replace('-', '', $uuid);
    }
}
