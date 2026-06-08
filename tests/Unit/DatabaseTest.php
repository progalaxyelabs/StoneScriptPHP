<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Database Unit Tests
 *
 * Tests the core database functionality including:
 * - Singleton pattern
 * - Database function calls
 * - Object mapping
 * - Error handling
 * - Type conversions
 */
class DatabaseTest extends TestCase
{
    /**
     * Test that Database uses singleton pattern
     */
    public function test_database_is_singleton(): void
    {
        $reflection = new \ReflectionClass(\StoneScriptPHP\Database::class);
        $constructor = $reflection->getConstructor();

        $this->assertTrue($constructor->isPrivate(), 'Constructor should be private for singleton');
    }

    /**
     * Test that fn() method requires valid function name
     */
    public function test_fn_requires_function_name(): void
    {
        $this->expectException(\TypeError::class);
        \StoneScriptPHP\Database::fn(null, []);
    }

    /**
     * Test that fn() accepts array parameters
     */
    public function test_fn_accepts_array_parameters(): void
    {
        // Integration test: Database::fn() calls the live gateway (v3 gateway-only).
        if (!getenv('DB_GATEWAY_URL')) {
            $this->markTestSkipped('Requires a live gateway (DB_GATEWAY_URL) — integration test.');
        }

        $functionName = 'test_function';
        $params = ['param1', 'param2'];

        $result = \StoneScriptPHP\Database::fn($functionName, $params);

        $this->assertIsArray($result);
    }

    /**
     * Test that result_as_object handles empty rows
     */
    public function test_result_as_object_handles_empty_rows(): void
    {
        $result = \StoneScriptPHP\Database::result_as_object('test_fn', [], 'stdClass');

        $this->assertNull($result);
    }

    /**
     * Test that result_as_table handles empty rows
     */
    public function test_result_as_table_handles_empty_rows(): void
    {
        $result = \StoneScriptPHP\Database::result_as_table('test_fn', [], 'stdClass');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test that array_to_class_object creates object from array
     */
    public function test_array_to_class_object_creates_object(): void
    {
        $testClass = new class {
            public string $name = '';
            public int $age = 0;
            public bool $active = false;
        };

        $row = [
            'name' => 'John Doe',
            'age' => '30',
            'active' => 't'
        ];

        $result = \StoneScriptPHP\Database::array_to_class_object(
            'test_fn',
            $row,
            get_class($testClass)
        );

        $this->assertInstanceOf(get_class($testClass), $result);
        $this->assertEquals('John Doe', $result->name);
        $this->assertEquals(30, $result->age);
        $this->assertTrue($result->active);
    }

    /**
     * Test that array_to_class_object handles null values for int
     */
    public function test_array_to_class_object_handles_null_int(): void
    {
        $testClass = new class {
            public int $count = 0;
        };

        $row = ['count' => null];

        $result = \StoneScriptPHP\Database::array_to_class_object(
            'test_fn',
            $row,
            get_class($testClass)
        );

        $this->assertEquals(0, $result->count);
    }

    /**
     * Test that array_to_class_object handles null values for bool
     */
    public function test_array_to_class_object_handles_null_bool(): void
    {
        $testClass = new class {
            public bool $enabled = false;
        };

        $row = ['enabled' => null];

        $result = \StoneScriptPHP\Database::array_to_class_object(
            'test_fn',
            $row,
            get_class($testClass)
        );

        $this->assertFalse($result->enabled);
    }

    /**
     * Test that array_to_class_object handles null values for string
     */
    public function test_array_to_class_object_handles_null_string(): void
    {
        $testClass = new class {
            public string $description = '';
        };

        $row = ['description' => null];

        $result = \StoneScriptPHP\Database::array_to_class_object(
            'test_fn',
            $row,
            get_class($testClass)
        );

        $this->assertEquals('', $result->description);
    }

    /**
     * Test that array_to_class_object converts PostgreSQL boolean 't' to true
     */
    public function test_array_to_class_object_converts_pg_bool_true(): void
    {
        $testClass = new class {
            public bool $active = false;
        };

        $row = ['active' => 't'];

        $result = \StoneScriptPHP\Database::array_to_class_object(
            'test_fn',
            $row,
            get_class($testClass)
        );

        $this->assertTrue($result->active);
    }

    /**
     * Test that array_to_class_object converts PostgreSQL boolean 'f' to false
     */
    public function test_array_to_class_object_converts_pg_bool_false(): void
    {
        $testClass = new class {
            public bool $active = true;
        };

        $row = ['active' => 'f'];

        $result = \StoneScriptPHP\Database::array_to_class_object(
            'test_fn',
            $row,
            get_class($testClass)
        );

        $this->assertFalse($result->active);
    }

    /**
     * Test that array_to_class_object converts a native JSON boolean `true`
     * (StoneScriptDB Gateway mode, post json_decode) to true.
     *
     * Regression guard for alert #5418: gateway-mode boolean output columns
     * were always coerced to false because the mapper only matched libpq text
     * 't'. Native PHP `true` from json_decode must map to true.
     */
    public function test_array_to_class_object_converts_native_json_bool_true(): void
    {
        $testClass = new class {
            public bool $o_inserted = false;
        };

        $row = ['o_inserted' => true];

        $result = \StoneScriptPHP\Database::array_to_class_object(
            'upsert_workspace_event',
            $row,
            get_class($testClass)
        );

        $this->assertTrue($result->o_inserted);
    }

    /**
     * Test that array_to_class_object converts a native JSON boolean `false`
     * (StoneScriptDB Gateway mode, post json_decode) to false.
     */
    public function test_array_to_class_object_converts_native_json_bool_false(): void
    {
        $testClass = new class {
            public bool $o_inserted = true;
        };

        $row = ['o_inserted' => false];

        $result = \StoneScriptPHP\Database::array_to_class_object(
            'upsert_workspace_event',
            $row,
            get_class($testClass)
        );

        $this->assertFalse($result->o_inserted);
    }

    /**
     * Test that array_to_class_object handles DateTime conversion
     */
    public function test_array_to_class_object_converts_datetime(): void
    {
        $testClass = new class {
            public \DateTime $created_at;
        };

        $row = ['created_at' => '2025-01-01 12:00:00'];

        $result = \StoneScriptPHP\Database::array_to_class_object(
            'test_fn',
            $row,
            get_class($testClass)
        );

        $this->assertInstanceOf(\DateTime::class, $result->created_at);
        $this->assertEquals('2025-01-01', $result->created_at->format('Y-m-d'));
    }

    /**
     * Test that array_to_class_object throws on missing properties
     */
    public function test_array_to_class_object_throws_on_missing_properties(): void
    {
        $testClass = new class {
            public string $required_field = '';
        };

        $row = ['other_field' => 'value'];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('mismatch in function result fields and class properties');

        \StoneScriptPHP\Database::array_to_class_object(
            'test_fn',
            $row,
            get_class($testClass)
        );
    }

    /**
     * Test that array_to_class_object handles out parameters with o_ prefix
     */
    public function test_array_to_class_object_handles_out_params(): void
    {
        $testClass = new class {
            public string $status = '';
            public int $code = 0;
        };

        $row = [
            'o_status' => 'success',
            'o_code' => '200'
        ];

        $result = \StoneScriptPHP\Database::array_to_class_object(
            'test_fn',
            $row,
            get_class($testClass),
            true
        );

        $this->assertEquals('success', $result->status);
        $this->assertEquals(200, $result->code);
    }

    /**
     * Test that result_as_table creates array of objects
     */
    public function test_result_as_table_creates_array_of_objects(): void
    {
        $testClass = new class {
            public string $name = '';
            public int $id = 0;
        };

        $rows = [
            ['name' => 'Alice', 'id' => '1'],
            ['name' => 'Bob', 'id' => '2'],
            ['name' => 'Charlie', 'id' => '3']
        ];

        $result = \StoneScriptPHP\Database::result_as_table(
            'test_fn',
            $rows,
            get_class($testClass)
        );

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals('Alice', $result[0]->name);
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals('Bob', $result[1]->name);
        $this->assertEquals('Charlie', $result[2]->name);
    }

    /**
     * Test that copy_from returns boolean
     */
    public function test_copy_from_returns_boolean(): void
    {
        // Integration test: copy_from() uses a direct PostgreSQL connection
        // (not the gateway), unavailable in a gateway-only unit context.
        if (!getenv('DB_GATEWAY_URL')) {
            $this->markTestSkipped('Requires a direct DB connection — integration test.');
        }

        $rows = ['row1', 'row2'];
        $tablename = 'test_table';
        $delimiter = ',';

        $result = \StoneScriptPHP\Database::copy_from($rows, $tablename, $delimiter);

        $this->assertIsBool($result);
    }

    /**
     * Test that query returns string result
     */
    public function test_query_returns_string(): void
    {
        // Integration test: query() uses a direct PostgreSQL connection
        // (not the gateway), unavailable in a gateway-only unit context.
        if (!getenv('DB_GATEWAY_URL')) {
            $this->markTestSkipped('Requires a direct DB connection — integration test.');
        }

        $sql = 'SELECT version()';

        $result = \StoneScriptPHP\Database::query($sql);

        $this->assertIsString($result);
    }

    /**
     * Test that internal_query throws in v3 gateway-only mode (behavior pinned).
     * Direct SQL was removed in v3 — use PostgreSQL functions via the gateway.
     */
    public function test_internal_query_throws_in_gateway_mode(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not available in gateway mode');

        \StoneScriptPHP\Database::internal_query('SELECT 1 as test');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // o_ output-column resolution (SPEC §5, Output Column Naming) — #2825
    // Regression guard: clean (unprefixed) model properties MUST map gateway
    // output whether the result keys are o_-prefixed or not, across all mappers,
    // WITHOUT the legacy $as_out_param flag. This is the cat-and-mouse killer:
    // regenerating a model (which strips o_) can never re-break mapping.
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Clean-props model maps an o_-prefixed row WITHOUT the as_out_param flag.
     * (This is the exact case that was broken — exact-name match missed o_id.)
     */
    public function test_clean_props_map_o_prefixed_row_without_flag(): void
    {
        $testClass = new class {
            public int $id = 0;
            public string $name = '';
        };

        $row = ['o_id' => '7', 'o_name' => 'Ada'];

        $result = \StoneScriptPHP\Database::array_to_class_object('fn', $row, get_class($testClass));

        $this->assertEquals(7, $result->id);
        $this->assertEquals('Ada', $result->name);
    }

    /**
     * Clean-props model still maps a legacy UNPREFIXED row (exact match first).
     */
    public function test_clean_props_map_unprefixed_legacy_row(): void
    {
        $testClass = new class {
            public int $id = 0;
            public string $name = '';
        };

        $row = ['id' => '7', 'name' => 'Ada'];

        $result = \StoneScriptPHP\Database::array_to_class_object('fn', $row, get_class($testClass));

        $this->assertEquals(7, $result->id);
        $this->assertEquals('Ada', $result->name);
    }

    /**
     * Hand-written o_-prefixed model property maps an o_-prefixed row (exact match).
     * Ensures we never reintroduce the o_o_id failure mode for such models.
     */
    public function test_hand_fixed_o_prefixed_prop_maps_o_prefixed_row(): void
    {
        $testClass = new class {
            public int $o_id = 0;
        };

        $row = ['o_id' => '7'];

        $result = \StoneScriptPHP\Database::array_to_class_object('fn', $row, get_class($testClass));

        $this->assertEquals(7, $result->o_id);
    }

    /**
     * result_as_table maps o_-prefixed rows to clean-props models — the exact
     * #2811 BLOCKER 1 scenario (RETURNS TABLE function output).
     */
    public function test_result_as_table_maps_o_prefixed_rows(): void
    {
        $testClass = new class {
            public int $id = 0;
            public string $name = '';
        };

        $rows = [
            ['o_id' => '1', 'o_name' => 'Alice'],
            ['o_id' => '2', 'o_name' => 'Bob'],
        ];

        $result = \StoneScriptPHP\Database::result_as_table('fn', $rows, get_class($testClass));

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals('Alice', $result[0]->name);
        $this->assertEquals(2, $result[1]->id);
        $this->assertEquals('Bob', $result[1]->name);
    }

    /**
     * result_as_single maps an o_-prefixed row to a clean-props model.
     */
    public function test_result_as_single_maps_o_prefixed_row(): void
    {
        $testClass = new class {
            public int $id = 0;
            public string $name = '';
        };

        $result = \StoneScriptPHP\Database::result_as_single('fn', [['o_id' => '9', 'o_name' => 'Zed']], get_class($testClass));

        $this->assertNotNull($result);
        $this->assertEquals(9, $result->id);
        $this->assertEquals('Zed', $result->name);
    }
}
