<?php

namespace Framework;

use Exception;

class Migrations
{
    private \PgSql\Connection|false $connection;
    private array $codeDefinitions = [];
    private array $dbDefinitions = [];
    private array $differences = [];

    public function __construct()
    {
        // Get database connection
        $this->connection = $this->getConnection();
    }

    /**
     * Get database connection from Database class
     */
    private function getConnection(): \PgSql\Connection|false
    {
        $env = Env::get_instance();

        $host = $env->DATABASE_HOST;
        $port = $env->DATABASE_PORT;
        $user = $env->DATABASE_USER;
        $password = $env->DATABASE_PASSWORD;
        $dbname = $env->DATABASE_DBNAME;
        $timeout = $env->DATABASE_TIMEOUT;
        $appname = $env->DATABASE_APPNAME;

        $connection_string = join(' ', [
            "host=$host",
            "port=$port",
            "user=$user",
            "password=$password",
            "dbname=$dbname",
            "connect_timeout=$timeout",
            "options='--application_name=$appname'"
        ]);

        return pg_connect($connection_string);
    }

    /**
     * Main verify method - checks for drift between code and database
     */
    public function verify(): array
    {
        echo "Verifying database schema against source files...\n\n";

        // Scan code files
        $this->codeDefinitions = $this->getCodeDefinitions();

        // Query database
        $this->dbDefinitions = $this->getDatabaseDefinitions();

        // Compare and find differences
        $this->differences = $this->diff($this->codeDefinitions, $this->dbDefinitions);

        // Output results
        $this->outputResults();

        return $this->differences;
    }

    /**
     * Scan filesystem for .pssql files and parse them
     * TODO: User will provide enhanced parsing algorithm
     */
    private function getCodeDefinitions(): array
    {
        $definitions = [
            'tables' => [],
            'functions' => []
        ];

        // Scan tables
        $tablesPath = ROOT_PATH . 'src/App/Database/postgresql/tables/';
        if (is_dir($tablesPath)) {
            $files = glob($tablesPath . '*.pssql');
            foreach ($files as $file) {
                $tableName = $this->parseTableName($file);
                if ($tableName) {
                    $definitions['tables'][$tableName] = $this->parseTableStructure($file);
                }
            }
        }

        // Scan functions
        $functionsPath = ROOT_PATH . 'src/App/Database/postgresql/functions/';
        if (is_dir($functionsPath)) {
            $files = glob($functionsPath . '*.pssql');
            foreach ($files as $file) {
                $functionName = $this->parseFunctionName($file);
                if ($functionName) {
                    $definitions['functions'][$functionName] = $this->parseFunctionStructure($file);
                }
            }
        }

        return $definitions;
    }

    /**
     * Query database for current schema
     */
    private function getDatabaseDefinitions(): array
    {
        $definitions = [
            'tables' => [],
            'functions' => []
        ];

        // Get all tables
        $tablesQuery = "
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = 'public'
            AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ";

        $result = pg_query($this->connection, $tablesQuery);
        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $tableName = $row['table_name'];
                $definitions['tables'][$tableName] = $this->getTableColumns($tableName);
            }
        }

        // Get all functions
        $functionsQuery = "
            SELECT
                routine_name,
                routine_definition,
                data_type as return_type
            FROM information_schema.routines
            WHERE routine_schema = 'public'
            AND routine_type = 'FUNCTION'
            ORDER BY routine_name
        ";

        $result = pg_query($this->connection, $functionsQuery);
        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $functionName = $row['routine_name'];
                $definitions['functions'][$functionName] = [
                    'return_type' => $row['return_type'],
                    'definition' => $row['routine_definition']
                ];
            }
        }

        return $definitions;
    }

    /**
     * Get column details for a specific table
     */
    private function getTableColumns(string $tableName): array
    {
        $columns = [];

        $query = "
            SELECT
                column_name,
                data_type,
                is_nullable,
                column_default,
                character_maximum_length
            FROM information_schema.columns
            WHERE table_schema = 'public'
            AND table_name = $1
            ORDER BY ordinal_position
        ";

        $result = pg_query_params($this->connection, $query, [$tableName]);
        if ($result) {
            while ($row = pg_fetch_assoc($result)) {
                $columns[$row['column_name']] = [
                    'type' => $row['data_type'],
                    'nullable' => $row['is_nullable'] === 'YES',
                    'default' => $row['column_default'],
                    'length' => $row['character_maximum_length']
                ];
            }
        }

        return $columns;
    }

    /**
     * Simple table name parser (placeholder - user will provide better algo)
     */
    private function parseTableName(string $filePath): ?string
    {
        $content = file_get_contents($filePath);

        // Simple regex to extract table name from CREATE TABLE statement
        if (preg_match('/create\s+table\s+(\w+)/i', $content, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Simple table structure parser (placeholder - user will provide better algo)
     */
    private function parseTableStructure(string $filePath): array
    {
        $content = file_get_contents($filePath);
        $columns = [];

        // Very basic parsing - extract column definitions
        // This is a PLACEHOLDER - user will provide better algorithm
        preg_match_all('/(\w+)\s+(text|integer|int|serial|bool|boolean|timestamptz|date|varchar)/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $columnName = strtolower($match[1]);
            $columnType = strtolower($match[2]);

            // Skip keywords
            if (in_array($columnName, ['create', 'table', 'primary', 'key', 'not', 'null', 'default'])) {
                continue;
            }

            $columns[$columnName] = [
                'type' => $this->normalizeType($columnType),
                'nullable' => !stripos($content, "$columnName " . $match[2] . " not null"),
                'default' => null
            ];
        }

        return $columns;
    }

    /**
     * Simple function name parser (placeholder)
     */
    private function parseFunctionName(string $filePath): ?string
    {
        $content = file_get_contents($filePath);

        if (preg_match('/create\s+(?:or\s+replace\s+)?function\s+(\w+)/i', $content, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * Simple function structure parser (placeholder)
     */
    private function parseFunctionStructure(string $filePath): array
    {
        $content = file_get_contents($filePath);

        return [
            'definition' => $content
        ];
    }

    /**
     * Normalize PostgreSQL type names for comparison
     */
    private function normalizeType(string $type): string
    {
        $typeMap = [
            'int' => 'integer',
            'int4' => 'integer',
            'bool' => 'boolean',
            'varchar' => 'character varying',
            'serial' => 'integer'
        ];

        return $typeMap[strtolower($type)] ?? strtolower($type);
    }

    /**
     * Compare code definitions with database definitions
     */
    private function diff(array $codeDefinitions, array $dbDefinitions): array
    {
        $differences = [
            'tables' => [
                'missing_in_db' => [],
                'missing_in_code' => [],
                'column_differences' => []
            ],
            'functions' => [
                'missing_in_db' => [],
                'missing_in_code' => [],
                'signature_differences' => []
            ]
        ];

        // Check tables
        $codeTables = array_keys($codeDefinitions['tables']);
        $dbTables = array_keys($dbDefinitions['tables']);

        // Tables in code but not in DB
        $differences['tables']['missing_in_db'] = array_diff($codeTables, $dbTables);

        // Tables in DB but not in code
        $differences['tables']['missing_in_code'] = array_diff($dbTables, $codeTables);

        // Check columns for tables that exist in both
        $commonTables = array_intersect($codeTables, $dbTables);
        foreach ($commonTables as $tableName) {
            $codeColumns = $codeDefinitions['tables'][$tableName];
            $dbColumns = $dbDefinitions['tables'][$tableName];

            $columnDiff = $this->compareColumns($tableName, $codeColumns, $dbColumns);
            if (!empty($columnDiff)) {
                $differences['tables']['column_differences'][$tableName] = $columnDiff;
            }
        }

        // Check functions
        $codeFunctions = array_keys($codeDefinitions['functions']);
        $dbFunctions = array_keys($dbDefinitions['functions']);

        // Functions in code but not in DB
        $differences['functions']['missing_in_db'] = array_diff($codeFunctions, $dbFunctions);

        // Functions in DB but not in code
        $differences['functions']['missing_in_code'] = array_diff($dbFunctions, $codeFunctions);

        return $differences;
    }

    /**
     * Compare columns between code and database
     */
    private function compareColumns(string $tableName, array $codeColumns, array $dbColumns): array
    {
        $differences = [
            'missing_in_db' => [],
            'missing_in_code' => [],
            'type_mismatch' => []
        ];

        $codeColumnNames = array_keys($codeColumns);
        $dbColumnNames = array_keys($dbColumns);

        // Columns in code but not in DB
        $differences['missing_in_db'] = array_diff($codeColumnNames, $dbColumnNames);

        // Columns in DB but not in code
        $differences['missing_in_code'] = array_diff($dbColumnNames, $codeColumnNames);

        // Check type mismatches for common columns
        $commonColumns = array_intersect($codeColumnNames, $dbColumnNames);
        foreach ($commonColumns as $columnName) {
            $codeType = $this->normalizeType($codeColumns[$columnName]['type']);
            $dbType = $this->normalizeType($dbColumns[$columnName]['type']);

            if ($codeType !== $dbType) {
                $differences['type_mismatch'][$columnName] = [
                    'code' => $codeType,
                    'db' => $dbType
                ];
            }
        }

        // Remove empty arrays
        $differences = array_filter($differences, function($arr) {
            return !empty($arr);
        });

        return $differences;
    }

    /**
     * Output formatted results to console
     */
    private function outputResults(): void
    {
        $hasIssues = false;
        $matchCount = 0;
        $issueCount = 0;

        // Check tables
        foreach ($this->differences['tables']['missing_in_db'] as $tableName) {
            echo "❌ Table '$tableName' exists in code but not in database\n";
            $hasIssues = true;
            $issueCount++;
        }

        foreach ($this->differences['tables']['missing_in_code'] as $tableName) {
            echo "❌ Table '$tableName' exists in database but not in code (tables/*.pssql)\n";
            $hasIssues = true;
            $issueCount++;
        }

        foreach ($this->differences['tables']['column_differences'] as $tableName => $columnDiff) {
            foreach ($columnDiff['missing_in_db'] ?? [] as $columnName) {
                echo "❌ Table '$tableName': column '$columnName' in code but not in database\n";
                $hasIssues = true;
                $issueCount++;
            }

            foreach ($columnDiff['missing_in_code'] ?? [] as $columnName) {
                echo "❌ Table '$tableName': column '$columnName' in database but not in code\n";
                $hasIssues = true;
                $issueCount++;
            }

            foreach ($columnDiff['type_mismatch'] ?? [] as $columnName => $types) {
                echo "⚠️  Table '$tableName': column '$columnName' type differs (code: {$types['code']}, db: {$types['db']})\n";
                $hasIssues = true;
                $issueCount++;
            }
        }

        // Check functions
        foreach ($this->differences['functions']['missing_in_db'] as $functionName) {
            echo "❌ Function '$functionName' exists in code but not in database\n";
            $hasIssues = true;
            $issueCount++;
        }

        foreach ($this->differences['functions']['missing_in_code'] as $functionName) {
            echo "❌ Function '$functionName' exists in database but not in code (functions/*.pssql)\n";
            $hasIssues = true;
            $issueCount++;
        }

        // Show matches
        $codeTables = count($this->codeDefinitions['tables']);
        $dbTables = count($this->dbDefinitions['tables']);
        $matchingTables = $codeTables - count($this->differences['tables']['missing_in_db']);

        if ($matchingTables > 0 && empty($this->differences['tables']['column_differences'])) {
            echo "✅ $matchingTables table(s) match\n";
            $matchCount += $matchingTables;
        }

        $codeFunctions = count($this->codeDefinitions['functions']);
        $dbFunctions = count($this->dbDefinitions['functions']);
        $matchingFunctions = $codeFunctions - count($this->differences['functions']['missing_in_db']);

        if ($matchingFunctions > 0) {
            echo "✅ $matchingFunctions function(s) match\n";
            $matchCount += $matchingFunctions;
        }

        // Summary
        echo "\n";
        echo "Summary:\n";
        if ($matchCount > 0) {
            echo "  ✅ $matchCount object(s) match\n";
        }
        if ($issueCount > 0) {
            echo "  ❌ $issueCount issue(s) found\n";
        }
        echo "\n";

        if ($hasIssues) {
            echo "Status: DRIFT DETECTED\n";
        } else {
            echo "Status: ✅ No drift detected - database matches source files\n";
        }
    }

    /**
     * Get exit code for CLI (0 = success, 1 = drift detected)
     */
    public function getExitCode(): int
    {
        $hasIssues =
            !empty($this->differences['tables']['missing_in_db']) ||
            !empty($this->differences['tables']['missing_in_code']) ||
            !empty($this->differences['tables']['column_differences']) ||
            !empty($this->differences['functions']['missing_in_db']) ||
            !empty($this->differences['functions']['missing_in_code']);

        return $hasIssues ? 1 : 0;
    }
}
