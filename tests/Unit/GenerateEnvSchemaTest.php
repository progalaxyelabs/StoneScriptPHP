<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fixture mimicking the StoneScriptPHP\Env public-typed-property model:
 * property name == ENV var name; default = property default; required = no
 * default + non-nullable; static/private excluded.
 */
class FixtureEnv
{
    public string $APP_NAME = 'My API';
    public int $APP_PORT = 9100;
    public bool $DEBUG_MODE = false;
    public float $RATE_LIMIT = 1.5;
    public string $DB_GATEWAY_URL;          // required: no default, non-nullable
    public ?string $OPTIONAL_TOKEN = null;  // optional: nullable
    public static string $STATIC_PROP = 'z'; // must be ignored (static)
    private string $hidden = 'no';           // must be ignored (private)
}

/**
 * Unit coverage for cli/generate-env.php::buildSchemaFromReflection() — the
 * reflection-based replacement for the removed Env::getSchema() (#2873).
 *
 * Guards against the exact regression that sat undetected since v2.3.0: the
 * generator silently drifting from the Env class's actual API.
 */
class GenerateEnvSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Expose the generator's helper functions without running the CLI flow.
        if (!defined('STONESCRIPTPHP_ENV_GENERATOR_LIB_ONLY')) {
            define('STONESCRIPTPHP_ENV_GENERATOR_LIB_ONLY', true);
        }
        require_once __DIR__ . '/../../cli/generate-env.php';
    }

    public function test_function_is_available(): void
    {
        $this->assertTrue(
            function_exists('buildSchemaFromReflection'),
            'buildSchemaFromReflection() must be loadable via the lib-only seam'
        );
    }

    public function test_derives_type_default_required_from_properties(): void
    {
        $schema = \buildSchemaFromReflection(new \ReflectionClass(FixtureEnv::class));

        // name == env var
        $this->assertArrayHasKey('APP_NAME', $schema);

        // type derivation
        $this->assertSame('string', $schema['APP_NAME']['type']);
        $this->assertSame('int', $schema['APP_PORT']['type']);
        $this->assertSame('bool', $schema['DEBUG_MODE']['type']);
        $this->assertSame('float', $schema['RATE_LIMIT']['type']);

        // default stringification for .env emission
        $this->assertSame('My API', $schema['APP_NAME']['default']);
        $this->assertSame('9100', $schema['APP_PORT']['default']);
        $this->assertSame('false', $schema['DEBUG_MODE']['default']); // bool -> 'false', not ''
        $this->assertSame('1.5', $schema['RATE_LIMIT']['default']);
        $this->assertNull($schema['DB_GATEWAY_URL']['default']);      // no default -> null
        $this->assertNull($schema['OPTIONAL_TOKEN']['default']);      // null default -> null

        // required heuristic: no default AND not nullable
        $this->assertTrue($schema['DB_GATEWAY_URL']['required'], 'no default + non-nullable => required');
        $this->assertFalse($schema['APP_NAME']['required'], 'has default => optional');
        $this->assertFalse($schema['OPTIONAL_TOKEN']['required'], 'nullable => optional');

        // description not derivable from the property model -> empty
        $this->assertSame('', $schema['APP_NAME']['description']);
    }

    public function test_excludes_static_and_private_properties(): void
    {
        $schema = \buildSchemaFromReflection(new \ReflectionClass(FixtureEnv::class));
        $this->assertArrayNotHasKey('STATIC_PROP', $schema, 'static props excluded');
        $this->assertArrayNotHasKey('hidden', $schema, 'private props excluded');
    }

    public function test_includes_inherited_and_subclass_properties(): void
    {
        if (!class_exists('Tests\\Unit\\FixtureChildEnv')) {
            eval('namespace Tests\\Unit; class FixtureChildEnv extends FixtureEnv { public string $CHILD_VAR = "c"; }');
        }
        $schema = \buildSchemaFromReflection(new \ReflectionClass('Tests\\Unit\\FixtureChildEnv'));
        $this->assertArrayHasKey('APP_NAME', $schema, 'inherited property present (App\\Env case)');
        $this->assertArrayHasKey('CHILD_VAR', $schema, 'subclass-declared property present');
    }

    public function test_matches_real_env_required_vars(): void
    {
        // Congruence with the real Env's own constructor validation: exactly
        // DB_GATEWAY_URL + DB_GATEWAY_PLATFORM are required.
        if (!class_exists('StoneScriptPHP\\Env')) {
            $this->markTestSkipped('StoneScriptPHP\\Env not autoloadable in this context');
        }
        $schema = \buildSchemaFromReflection(new \ReflectionClass('StoneScriptPHP\\Env'));
        $required = array_keys(array_filter($schema, fn($c) => $c['required']));
        sort($required);
        $this->assertSame(['DB_GATEWAY_PLATFORM', 'DB_GATEWAY_URL'], $required);
    }
}
