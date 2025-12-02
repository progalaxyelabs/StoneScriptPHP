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
        $reflection = new \ReflectionClass(\Framework\Database::class);
        $constructor = $reflection->getConstructor();

        $this->assertTrue($constructor->isPrivate(), 'Constructor should be private for singleton');
    }

    /**
     * Test that fn() method requires valid function name
     */
    public function test_fn_requires_function_name(): void
    {
        $this->expectException(\TypeError::class);
        \Framework\Database::fn(null, []);
    }

    /**
     * Test that fn() accepts array parameters
     */
    public function test_fn_accepts_array_parameters(): void
    {
        $functionName = 'test_function';
        $params = ['param1', 'param2'];

        $result = \Framework\Database::fn($functionName, $params);

        $this->assertIsArray($result);
    }

    /**
     * Test that result_as_object handles empty rows
     */
    public function test_result_as_object_handles_empty_rows(): void
    {
        $result = \Framework\Database::result_as_object('test_fn', [], 'stdClass');

        $this->assertNull($result);
    }

    /**
     * Test that result_as_table handles empty rows
     */
    public function test_result_as_table_handles_empty_rows(): void
    {
        $result = \Framework\Database::result_as_table('test_fn', [], 'stdClass');

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

        $result = \Framework\Database::array_to_class_object(
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

        $result = \Framework\Database::array_to_class_object(
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

        $result = \Framework\Database::array_to_class_object(
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

        $result = \Framework\Database::array_to_class_object(
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

        $result = \Framework\Database::array_to_class_object(
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

        $result = \Framework\Database::array_to_class_object(
            'test_fn',
            $row,
            get_class($testClass)
        );

        $this->assertFalse($result->active);
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

        $result = \Framework\Database::array_to_class_object(
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

        \Framework\Database::array_to_class_object(
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

        $result = \Framework\Database::array_to_class_object(
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

        $result = \Framework\Database::result_as_table(
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
        $rows = ['row1', 'row2'];
        $tablename = 'test_table';
        $delimiter = ',';

        $result = \Framework\Database::copy_from($rows, $tablename, $delimiter);

        $this->assertIsBool($result);
    }

    /**
     * Test that query returns string result
     */
    public function test_query_returns_string(): void
    {
        $sql = 'SELECT version()';

        $result = \Framework\Database::query($sql);

        $this->assertIsString($result);
    }

    /**
     * Test that internal_query returns array
     */
    public function test_internal_query_returns_array(): void
    {
        $sql = 'SELECT 1 as test';

        $result = \Framework\Database::internal_query($sql);

        $this->assertIsArray($result);
    }
}
