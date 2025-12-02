<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Migrations Unit Tests
 *
 * Tests the migration verification system including:
 * - Schema drift detection
 * - Table comparison
 * - Column comparison
 * - Function comparison
 * - Type normalization
 */
class MigrationsTest extends TestCase
{
    /**
     * Test that Migrations class can be instantiated
     */
    public function test_migrations_can_be_instantiated(): void
    {
        $migrations = new \Framework\Migrations();

        $this->assertInstanceOf(\Framework\Migrations::class, $migrations);
    }

    /**
     * Test that verify() returns array
     */
    public function test_verify_returns_array(): void
    {
        $migrations = new \Framework\Migrations();
        $result = $migrations->verify();

        $this->assertIsArray($result);
    }

    /**
     * Test that verify() result has expected structure
     */
    public function test_verify_result_has_expected_structure(): void
    {
        $migrations = new \Framework\Migrations();
        $result = $migrations->verify();

        $this->assertArrayHasKey('tables', $result);
        $this->assertArrayHasKey('functions', $result);

        $this->assertArrayHasKey('missing_in_db', $result['tables']);
        $this->assertArrayHasKey('missing_in_code', $result['tables']);
        $this->assertArrayHasKey('column_differences', $result['tables']);

        $this->assertArrayHasKey('missing_in_db', $result['functions']);
        $this->assertArrayHasKey('missing_in_code', $result['functions']);
        $this->assertArrayHasKey('signature_differences', $result['functions']);
    }

    /**
     * Test that getExitCode() returns 0 when no drift
     */
    public function test_get_exit_code_returns_zero_when_no_drift(): void
    {
        $migrations = new \Framework\Migrations();
        $migrations->verify();

        $exitCode = $migrations->getExitCode();

        $this->assertIsInt($exitCode);
        $this->assertContains($exitCode, [0, 1]);
    }

    /**
     * Test that parseTableName extracts table name from CREATE TABLE
     */
    public function test_parse_table_name_extracts_from_create_statement(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('parseTableName');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_table_');
        file_put_contents($tempFile, 'CREATE TABLE users (id serial PRIMARY KEY);');

        $result = $method->invoke($migrations, $tempFile);

        unlink($tempFile);

        $this->assertEquals('users', $result);
    }

    /**
     * Test that parseTableName returns null for invalid content
     */
    public function test_parse_table_name_returns_null_for_invalid(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('parseTableName');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_invalid_');
        file_put_contents($tempFile, 'INVALID SQL CONTENT');

        $result = $method->invoke($migrations, $tempFile);

        unlink($tempFile);

        $this->assertNull($result);
    }

    /**
     * Test that parseFunctionName extracts function name
     */
    public function test_parse_function_name_extracts_from_create_statement(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('parseFunctionName');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_function_');
        file_put_contents($tempFile, 'CREATE FUNCTION get_user(id integer) RETURNS TABLE...');

        $result = $method->invoke($migrations, $tempFile);

        unlink($tempFile);

        $this->assertEquals('get_user', $result);
    }

    /**
     * Test that parseFunctionName handles OR REPLACE
     */
    public function test_parse_function_name_handles_or_replace(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('parseFunctionName');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $tempFile = tempnam(sys_get_temp_dir(), 'test_function_');
        file_put_contents($tempFile, 'CREATE OR REPLACE FUNCTION update_timestamp() RETURNS trigger...');

        $result = $method->invoke($migrations, $tempFile);

        unlink($tempFile);

        $this->assertEquals('update_timestamp', $result);
    }

    /**
     * Test that normalizeType converts int to integer
     */
    public function test_normalize_type_converts_int_to_integer(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('normalizeType');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $this->assertEquals('integer', $method->invoke($migrations, 'int'));
        $this->assertEquals('integer', $method->invoke($migrations, 'int4'));
        $this->assertEquals('integer', $method->invoke($migrations, 'serial'));
    }

    /**
     * Test that normalizeType converts bool to boolean
     */
    public function test_normalize_type_converts_bool_to_boolean(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('normalizeType');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $this->assertEquals('boolean', $method->invoke($migrations, 'bool'));
    }

    /**
     * Test that normalizeType converts varchar to character varying
     */
    public function test_normalize_type_converts_varchar(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('normalizeType');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $this->assertEquals('character varying', $method->invoke($migrations, 'varchar'));
    }

    /**
     * Test that normalizeType preserves unknown types
     */
    public function test_normalize_type_preserves_unknown_types(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('normalizeType');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $this->assertEquals('text', $method->invoke($migrations, 'text'));
        $this->assertEquals('timestamptz', $method->invoke($migrations, 'timestamptz'));
    }

    /**
     * Test that normalizeType is case insensitive
     */
    public function test_normalize_type_is_case_insensitive(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('normalizeType');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $this->assertEquals('integer', $method->invoke($migrations, 'INT'));
        $this->assertEquals('integer', $method->invoke($migrations, 'Int'));
        $this->assertEquals('boolean', $method->invoke($migrations, 'BOOL'));
    }

    /**
     * Test that compareColumns detects missing columns in DB
     */
    public function test_compare_columns_detects_missing_in_db(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('compareColumns');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $codeColumns = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'text'],
            'email' => ['type' => 'text']
        ];

        $dbColumns = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'text']
        ];

        $result = $method->invoke($migrations, 'users', $codeColumns, $dbColumns);

        $this->assertArrayHasKey('missing_in_db', $result);
        $this->assertContains('email', $result['missing_in_db']);
    }

    /**
     * Test that compareColumns detects missing columns in code
     */
    public function test_compare_columns_detects_missing_in_code(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('compareColumns');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $codeColumns = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'text']
        ];

        $dbColumns = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'text'],
            'deprecated_field' => ['type' => 'text']
        ];

        $result = $method->invoke($migrations, 'users', $codeColumns, $dbColumns);

        $this->assertArrayHasKey('missing_in_code', $result);
        $this->assertContains('deprecated_field', $result['missing_in_code']);
    }

    /**
     * Test that compareColumns detects type mismatches
     */
    public function test_compare_columns_detects_type_mismatch(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('compareColumns');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $codeColumns = [
            'id' => ['type' => 'integer'],
            'count' => ['type' => 'integer']
        ];

        $dbColumns = [
            'id' => ['type' => 'integer'],
            'count' => ['type' => 'text']
        ];

        $result = $method->invoke($migrations, 'users', $codeColumns, $dbColumns);

        $this->assertArrayHasKey('type_mismatch', $result);
        $this->assertArrayHasKey('count', $result['type_mismatch']);
        $this->assertEquals('integer', $result['type_mismatch']['count']['code']);
        $this->assertEquals('text', $result['type_mismatch']['count']['db']);
    }

    /**
     * Test that compareColumns returns empty array when columns match
     */
    public function test_compare_columns_returns_empty_when_match(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('compareColumns');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $codeColumns = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'text']
        ];

        $dbColumns = [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'text']
        ];

        $result = $method->invoke($migrations, 'users', $codeColumns, $dbColumns);

        $this->assertEmpty($result);
    }

    /**
     * Test that diff detects tables missing in DB
     */
    public function test_diff_detects_tables_missing_in_db(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('diff');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $codeDefinitions = [
            'tables' => ['users' => [], 'posts' => []],
            'functions' => []
        ];

        $dbDefinitions = [
            'tables' => ['users' => []],
            'functions' => []
        ];

        $result = $method->invoke($migrations, $codeDefinitions, $dbDefinitions);

        $this->assertContains('posts', $result['tables']['missing_in_db']);
    }

    /**
     * Test that diff detects tables missing in code
     */
    public function test_diff_detects_tables_missing_in_code(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('diff');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $codeDefinitions = [
            'tables' => ['users' => []],
            'functions' => []
        ];

        $dbDefinitions = [
            'tables' => ['users' => [], 'legacy_table' => []],
            'functions' => []
        ];

        $result = $method->invoke($migrations, $codeDefinitions, $dbDefinitions);

        $this->assertContains('legacy_table', $result['tables']['missing_in_code']);
    }

    /**
     * Test that diff detects functions missing in DB
     */
    public function test_diff_detects_functions_missing_in_db(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('diff');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $codeDefinitions = [
            'tables' => [],
            'functions' => ['get_user' => [], 'create_user' => []]
        ];

        $dbDefinitions = [
            'tables' => [],
            'functions' => ['get_user' => []]
        ];

        $result = $method->invoke($migrations, $codeDefinitions, $dbDefinitions);

        $this->assertContains('create_user', $result['functions']['missing_in_db']);
    }

    /**
     * Test that diff detects functions missing in code
     */
    public function test_diff_detects_functions_missing_in_code(): void
    {
        $reflection = new \ReflectionClass(\Framework\Migrations::class);
        $method = $reflection->getMethod('diff');
        $method->setAccessible(true);

        $migrations = new \Framework\Migrations();

        $codeDefinitions = [
            'tables' => [],
            'functions' => ['get_user' => []]
        ];

        $dbDefinitions = [
            'tables' => [],
            'functions' => ['get_user' => [], 'deprecated_fn' => []]
        ];

        $result = $method->invoke($migrations, $codeDefinitions, $dbDefinitions);

        $this->assertContains('deprecated_fn', $result['functions']['missing_in_code']);
    }
}
