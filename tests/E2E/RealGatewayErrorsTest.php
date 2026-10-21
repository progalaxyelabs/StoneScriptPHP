<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use StoneScriptDB\GatewayClient;
use StoneScriptDB\GatewayException;

/**
 * Every failure class, through the REAL gateway and a REAL PostgreSQL 15, asserted twice:
 *   (1) in-process with the gateway client, and
 *   (2) in a child process through Database::fn() + DbErrorMapper (the framework path), including an uncaught run.
 * Nothing customer-grade may appear in a message, __toString, print_r, serialize, var_export, a log, stdout or stderr,
 * while the exact SQLSTATE / constraint / table / column / app code is extracted and the raw cause stays available for
 * debugging through getRawCauseForDebug().
 */
final class RealGatewayErrorsTest extends RealGatewayTestCase
{
    /** @var array<string,string> */
    private static array $unique = [];

    private static function expand(string $t): string
    {
        return strtr($t, ['{E}' => 'jane.sentinel', '{D}' => 'DETAIL', '{F}' => 'Failing row', '{NP}' => 'is not present in table', '{SK}' => 'smuggled_key', '{A}' => 'attacker text']);
    }

    private function uniqueEmail(): string
    {
        return 'jane.sentinel+' . bin2hex(random_bytes(3)) . '@e2e.test';
    }

    /**
     * Scenario: [function, params-builder key, expected]. Params are built per run (unique emails).
     * expected keys: sqlstate|null, constraint|null, column|null, table|null, app|null, msg (substrings),
     * not_msg (substrings that must NOT be in the message), status (framework DbErrorMapper status), raw (substring in raw cause)
     *
     * @return array<string,array{0:string,1:array}>
     */
    public static function scenarios(): array
    {
        $E = self::EMAIL;
        return [
            'unique violation' => ['unique', [
                'sqlstate' => '23505', 'constraint' => 'users_email_key', 'table' => 'users', 'app' => null,
                'msg' => ['sqlstate=23505', 'constraint=users_email_key', 'table=users', 'function=create_user'], 'status' => 409, 'raw' => '{E}',
            ]],
            'foreign key violation' => ['fk', [
                'sqlstate' => '23503', 'constraint' => 'members_user_fk', 'table' => 'members', 'app' => null,
                'msg' => ['sqlstate=23503', 'constraint=members_user_fk'], 'status' => 400, 'raw' => '{NP}',
            ]],
            'not-null violation' => ['notnull', [
                'sqlstate' => '23502', 'column' => 'name', 'table' => 'users', 'app' => null,
                'msg' => ['sqlstate=23502', 'column=name', 'table=users'], 'status' => 500, 'raw' => '{F}',
            ]],
            'check violation' => ['check', [
                'sqlstate' => '23514', 'constraint' => 'users_age_check', 'table' => 'users', 'app' => null,
                'msg' => ['sqlstate=23514', 'constraint=users_age_check'], 'status' => 422, 'raw' => '{F}',
            ]],
            'bad uuid' => ['baduuid', [
                'sqlstate' => '22P02', 'app' => null, 'msg' => ['sqlstate=22P02'], 'status' => 400, 'raw' => 'invalid input syntax for type uuid',
            ]],
            'bad int' => ['badint', [
                'sqlstate' => '22P02', 'app' => null, 'msg' => ['sqlstate=22P02'], 'status' => 400, 'raw' => 'invalid input syntax for type integer',
            ]],
            'missing function' => ['nofn', [
                'sqlstate' => '42883', 'app' => null, 'msg' => ['sqlstate=42883', 'function=no_such_fn'], 'status' => 500, 'raw' => 'does not exist',
            ]],
            'missing table' => ['notable', [
                'sqlstate' => '42P01', 'table' => 'ghost_table', 'app' => null, 'msg' => ['sqlstate=42P01', 'table=ghost_table'], 'status' => 500, 'raw' => 'ghost_table',
            ]],
            'raise code' => ['raise:code', [
                'sqlstate' => null, 'app' => 'not_found', 'msg' => ['code=not_found'], 'status' => 500, 'raw' => 'not_found',
            ]],
            'raise code slug_taken' => ['raise:code_slug', [
                'sqlstate' => null, 'app' => 'slug_taken', 'msg' => ['code=slug_taken'], 'status' => 500, 'raw' => 'slug_taken',
            ]],
            'raise code with free text' => ['raise:code_text', [
                'sqlstate' => null, 'app' => 'job_conflict:', 'msg' => ['code=job_conflict:'], 'status' => 500, 'raw' => '{E}',
            ]],
            'raise code colon id' => ['raise:code_colon', [
                'sqlstate' => null, 'app' => 'not_found:', 'msg' => ['code=not_found:'], 'status' => 500, 'raw' => 'workspace id={E}',
            ]],
            'raise bracket ER' => ['raise:tag_er', [
                'sqlstate' => null, 'app' => '[ER102]', 'msg' => ['code=[ER102]'], 'status' => 500, 'raw' => 'stock short for {E}',
            ]],
            'raise bracket public' => ['raise:tag_public', [
                // a [public:...] RAISE is the developer deliberately publishing its (value-free) text to the API client
                'sqlstate' => null, 'app' => '[public:code:409]', 'msg' => ['code=[public:code:409]'], 'status' => 409, 'raw' => 'already here',
                'public_text' => 'already here',
            ]],
            'raise sentence' => ['raise:sentence', [
                'sqlstate' => null, 'app' => null, 'not_msg' => ['code='], 'status' => 500, 'raw' => 'Insufficient stock for item',
            ]],
            'raise value-interpolating' => ['raise:value_exists', [
                'sqlstate' => null, 'app' => null, 'not_msg' => ['code='], 'status' => 500, 'raw' => '{E}',
            ]],
            'raise sentence with value' => ['raise:value_only', [
                'sqlstate' => null, 'app' => null, 'not_msg' => ['code='], 'status' => 500, 'raw' => 'User {E}',
            ]],
            'raise code only in secondary fields' => ['raise:detail', [
                'sqlstate' => null, 'app' => null, 'not_msg' => ['code='], 'status' => 500, 'raw' => '{D}',
            ]],
            // a user-submitted value carrying the public marker, echoed by PostgreSQL in DETAIL: Failing row contains (...)
            'check violation with marker in a value' => ['check_attack', [
                'sqlstate' => '23514', 'constraint' => 'users_age_check', 'table' => 'users', 'app' => null,
                'msg' => ['sqlstate=23514'], 'status' => 422, 'raw' => '{A}', 'generic_response' => 'Some of the details',
            ]],
            // values interpolated by RAISE '%' must never be taken for a code
            'raise bare value Rahul' => ['raise:bare_value@Rahul', ['sqlstate' => null, 'app' => null, 'not_msg' => ['code=', 'Rahul'], 'status' => 500, 'raw' => 'Rahul']],
            'raise bare value jdoe' => ['raise:bare_value@jdoe', ['sqlstate' => null, 'app' => null, 'not_msg' => ['code=', 'jdoe'], 'status' => 500, 'raw' => 'jdoe']],
            'raise bare value dotted' => ['raise:bare_value@john.doe', ['sqlstate' => null, 'app' => null, 'not_msg' => ['code=', 'john.doe'], 'status' => 500, 'raw' => 'john.doe']],
            'raise bare value dashed' => ['raise:bare_value@john-smith', ['sqlstate' => null, 'app' => null, 'not_msg' => ['code=', 'john-smith'], 'status' => 500, 'raw' => 'john-smith']],
            'raise bare value uuid' => ['raise:bare_value@9f86d081-884c-7d65-9a2f-eaa0c55ad015', ['sqlstate' => null, 'app' => null, 'not_msg' => ['code=', '9f86d081'], 'status' => 500, 'raw' => '9f86d081-884c']],
            'raise smuggling attempt' => ['raise:smuggle', [
                'sqlstate' => null, 'constraint' => null, 'column' => null, 'app' => null, 'not_msg' => ['code=', 'constraint=', 'column=', 'smuggl' . 'ed'],
                'status' => 500, 'raw' => '{SK}',
            ]],
        ];
    }

    /** @return array{0:string,1:list<mixed>} function and params for a scenario key */
    private function call(string $key): array
    {
        $e = self::EMAIL;
        if (str_starts_with($key, 'raise:')) {
            [$kind, $val] = array_pad(explode('@', substr($key, 6), 2), 2, $e);
            return ['raise_it', [$kind, $val]];
        }
        switch ($key) {
            case 'unique':
                $email = $this->uniqueEmail();
                self::$unique[$key] = $email;
                $this->client()->callFunction('create_user', [$email, 'Seeded One', 30]);
                return ['create_user', [$email, 'Dup', 31]];
            case 'fk':
                return ['create_member', ['00000000-0000-0000-0000-0000000000a1', '00000000-0000-0000-0000-0000000000a2']];
            case 'notnull':
                return ['create_user', [$this->uniqueEmail(), null, 3]];
            case 'check':
                return ['create_user', [$this->uniqueEmail(), 'Neg', -5]];
            case 'check_attack':
                // the user supplies a name that looks like the public marker; PostgreSQL echoes it (and the e-mail) in DETAIL
                return ['create_user', [$this->uniqueEmail(), '[public:x:404] attacker text', -5]];
            case 'baduuid':
                return ['user_by_uuid', ['not-a-uuid-' . $e]];
            case 'badint':
                return ['double_it', ['abc-' . $e]];
            case 'nofn':
                return ['no_such_fn', [$e, self::PHONE]];
            case 'notable':
                return ['ghost_table_fn', []];
        }
        throw new \LogicException($key);
    }

    private function client(): GatewayClient
    {
        return new GatewayClient(self::$url, $this->sharedPlatform(), 'main', null, null);
    }

    /** @param array<string,mixed> $x */
    #[DataProvider('scenarios')]
    public function testInProcessClient(string $key, array $x): void
    {
        [$fn, $params] = $this->call($key);
        $client = $this->client();
        try {
            $client->callFunction($fn, $params);
            $this->fail('the call must fail');
        } catch (GatewayException $e) {
            $this->assertExtraction($e, $x);
            foreach ([$e->getMessage(), (string) $e, print_r($e, true), serialize($e), var_export($e, true), print_r((array) $e, true), (string) $client->getLastError()] as $i => $form) {
                $this->assertClean($form, "client form #$i");
            }
            $this->assertNotNull($e->getRawCauseForDebug(), 'raw cause stays available for debugging');
            $this->assertStringContainsString(self::expand($x['raw']), $e->getRawCauseForDebug());
            $this->assertNull($e->getPrevious());
        }
    }

    /** @param array<string,mixed> $x */
    #[DataProvider('scenarios')]
    public function testFrameworkDatabaseFnAndMapper(string $key, array $x): void
    {
        [$fn, $params] = $this->call($key);
        $logDir = sys_get_temp_dir() . '/stonescript-e2e-logs-' . bin2hex(random_bytes(4));
        mkdir($logDir, 0775, true);
        [$out, $err, $code] = $this->runPhp(
            [__DIR__ . '/fixtures/e2e_db_fn.php', $fn, (string) json_encode($params), 'catch'],
            $this->childEnv($logDir)
        );
        $this->assertSame(0, $code, "child failed:\n$out\n$err");
        $this->assertSame(1, preg_match('/^E2E_RESULT:(.*)$/m', $out, $m), "no result line in:\n$out");
        $r = json_decode($m[1], true);
        $this->assertFalse($r['ok']);
        $this->assertSame($x['sqlstate'] ?? null, $r['sqlstate']);
        $this->assertSame($x['constraint'] ?? null, $r['constraint']);
        $this->assertSame($x['column'] ?? null, $r['column']);
        if (array_key_exists('table', $x)) {
            $this->assertSame($x['table'], $r['table']);
        }
        $this->assertSame($x['app'], $r['app_code']);
        $this->assertTrue($r['raw_has_text'], 'raw cause stays available through the debug accessor');
        $this->assertSame($x['status'], $r['mapped_status'], 'framework mapping: ' . $r['mapped_message']);
        foreach ($x['msg'] ?? [] as $needle) {
            $this->assertStringContainsString($needle, $r['message']);
        }
        foreach ($x['not_msg'] ?? [] as $needle) {
            $this->assertStringNotContainsString($needle, $r['message']);
        }
        foreach (['message', 'string', 'print_r', 'describe'] as $k) {
            $this->assertClean($r[$k], "framework $k");
        }
        $this->assertClean($r['mapped_message'], 'client-facing mapped message');
        if (isset($x['public_text'])) {
            $this->assertSame($x['public_text'], $r['mapped_message'], 'a [public:..] RAISE publishes exactly its own text');
        }
        if (isset($x['generic_response'])) {
            $this->assertStringContainsString($x['generic_response'], $r['mapped_message'], 'attacker text must not become the response');
        }
        // the result line is the harness's own report (its fields are asserted one by one above); everything else is real output
        $this->assertClean(preg_replace('/^E2E_RESULT:.*$/m', '', $out) . $err, 'child stdout+stderr');
        $logs = '';
        foreach (glob($logDir . '/*') ?: [] as $f) {
            $logs .= (string) file_get_contents($f);
        }
        $this->assertNotSame('', $logs, 'the framework wrote a log');
        $this->assertClean($logs, 'framework log file');
    }

    /** @param array<string,mixed> $x */
    #[DataProvider('scenarios')]
    public function testFrameworkUncaught(string $key, array $x): void
    {
        [$fn, $params] = $this->call($key);
        $logDir = sys_get_temp_dir() . '/stonescript-e2e-logs-' . bin2hex(random_bytes(4));
        mkdir($logDir, 0775, true);
        [$out, $err, $code] = $this->runPhp(
            [__DIR__ . '/fixtures/e2e_db_fn.php', $fn, (string) json_encode($params), 'uncaught'],
            $this->childEnv($logDir)
        );
        $this->assertNotSame(0, $code);
        $this->assertClean($out, 'uncaught stdout');
        $this->assertClean($err, 'uncaught stderr');
        $logs = '';
        foreach (glob($logDir . '/*') ?: [] as $f) {
            $logs .= (string) file_get_contents($f);
        }
        $this->assertNotSame('', $logs, 'the exception handler wrote a log');
        $this->assertClean($logs, 'uncaught log file');
    }

    public function testGatewayItselfLogsNoSentinels(): void
    {
        // runs after the data-provider tests have produced failures; the 4.8.2 gateway must not log the values either
        $logs = $this->gatewayLogs();
        $this->assertNotSame('', $logs, 'gateway log not found: STONESCRIPT_E2E_GATEWAY_LOG_DIR');
        $this->assertClean($logs, 'gateway logs');
    }

    /** @param array<string,mixed> $x */
    private function assertExtraction(GatewayException $e, array $x): void
    {
        $this->assertSame($x['sqlstate'] ?? null, $e->getSqlState(), 'sqlstate');
        $this->assertSame($x['constraint'] ?? null, $e->getConstraint(), 'constraint');
        $this->assertSame($x['column'] ?? null, $e->getColumn(), 'column');
        if (array_key_exists('table', $x)) {
            $this->assertSame($x['table'], $e->getSafeContext()['table'] ?? null, 'table');
        }
        $this->assertSame($x['app'], $e->getAppErrorCode(), 'app error code');
        $this->assertSame('query_failed', $e->getGatewayError());
        $this->assertNotNull($e->getClientRef(), 'real gateway sends no correlation id yet: client_ref is generated and labelled as such');
        $this->assertNull($e->getCorrelationId());
        foreach ($x['msg'] ?? [] as $needle) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
        foreach ($x['not_msg'] ?? [] as $needle) {
            $this->assertStringNotContainsString($needle, $e->getMessage());
        }
    }

    /** @return array<string,string> */
    private function childEnv(string $logDir): array
    {
        return [
            'DB_GATEWAY_URL' => self::$url,
            'DB_GATEWAY_PLATFORM' => $this->sharedPlatform(),
            'DB_GATEWAY_SCHEMA_NAME' => 'main',
            'STONESCRIPTPHP_LOG_DIR' => $logDir,
        ];
    }
}
