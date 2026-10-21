<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptDB\GatewayException;
use StoneScriptPHP\Db\DbTransportException;
use StoneScriptPHP\Persistence\DbErrorMapper;
use StoneScriptPHP\Persistence\PublicRaiseLint;

/**
 * The `[public:code:status] text` RAISE marker publishes its text to the API client. It must be honoured ONLY when it
 * is the start of the PostgreSQL primary message, never when it is found in DETAIL / HINT / CONTEXT, where PostgreSQL
 * echoes user-submitted values and other columns of the row.
 */
final class PublicMarkerTest extends TestCase
{
    private function gw(string $cause): GatewayException
    {
        return new GatewayException('Gateway returned HTTP 500: query_failed', 500, null, ['error' => 'query_failed', 'cause' => $cause]);
    }

    public function testMarkerInUserValueEchoedInDetailIsNotPublic(): void
    {
        // a check violation: the user's `name` field is a marker, PostgreSQL echoes the whole row (incl. other columns)
        $e = $this->gw('new row for relation "users" violates check constraint "users_age_check" | DETAIL: Failing row contains (7, [public:x:404] attacker text, victim@example.test, secret-column-value, -5). | SCHEMA: public | TABLE: users');
        [$status, $message] = DbErrorMapper::classify($e);
        $this->assertSame(422, $status, 'the status comes from the SQLSTATE class, not from attacker text');
        $this->assertStringNotContainsString('attacker', $message);
        $this->assertStringNotContainsString('victim', $message);
        $this->assertStringNotContainsString('secret-column-value', $message);
        $this->assertStringNotContainsString('Failing row', $message);
        $r = DbErrorMapper::resolve($e);
        $this->assertFalse($r->public ?? false);
        $this->assertNull($r->errorCode);
    }

    public function testMarkerInDetailHintContextOfARaiseIsNotPublic(): void
    {
        foreach (['DETAIL', 'HINT', 'CONTEXT'] as $field) {
            $e = $this->gw("Something failed | $field: [public:x:404] attacker text");
            [$status, $message] = DbErrorMapper::classify($e);
            $this->assertSame(500, $status, $field);
            $this->assertStringNotContainsString('attacker', $message, $field);
        }
        // inline rendering used by some drivers
        $e = $this->gw('Something failed DETAIL:  [public:x:404] attacker text');
        $this->assertSame(500, DbErrorMapper::classify($e)[0]);
    }

    public function testMidPrimaryLineDeveloperFormStillWorks(): void
    {
        // a wrapper-prefixed RAISE, the form the framework's own parity tests use
        $r = DbErrorMapper::resolve($this->gw('Failed to create order: [public:insufficient_quantity:409] Insufficient quantity in selected warehouse (SQLSTATE: P0001)'));
        $this->assertSame(409, $r->status);
        $this->assertSame('Insufficient quantity in selected warehouse', $r->message, 'text is bounded by the end of the primary line / (SQLSTATE');
        $this->assertSame('insufficient_quantity', $r->errorCode);
        $this->assertTrue($r->public);

        // with a structured P0001 SQLSTATE too
        $e = new GatewayException('x', 500, null, ['error' => 'query_failed', 'sqlstate' => 'P0001', 'cause' => 'Order rejected: [public:rejected:422] Not allowed | DETAIL: [public:x:404] attacker text']);
        $r = DbErrorMapper::resolve($e);
        $this->assertSame([422, 'Not allowed'], [$r->status, $r->message]);
    }

    public function testForgedMarkerInsideFailingRowDetailIsNotPublic(): void
    {
        $e = $this->gw('new row for relation "orders" violates check constraint "qty_positive" | DETAIL: Failing row contains (12, Failed to save: [public:x:404] attacker text, 3). | SCHEMA: public | TABLE: orders');
        $r = DbErrorMapper::resolve($e);
        $this->assertSame(422, $r->status);
        $this->assertStringNotContainsString('attacker', $r->message);
        $this->assertNull($r->errorCode);
    }

    public function testMarkerMustBeOnThePrimaryLineAndNotEchoedByPostgres(): void
    {
        $cases = [
            "line1\n[public:x:404] attacker text",                                             // second line
            'invalid input syntax for type uuid: "[public:x:404] attacker text"',               // echoed value, system error (22P02)
            'value "[public:x:404] attacker text" is bogus',                                    // echoed in quotes, unknown phrasing
            'malformed thing (see [public:x:404] attacker text)',                               // echoed in parentheses
            'key=[public:x:404] attacker text',                                                 // echoed after =
        ];
        foreach ($cases as $cause) {
            $r = DbErrorMapper::resolve($this->gw($cause));
            $this->assertStringNotContainsString('attacker', $r->message, $cause);
            $this->assertNotSame(404, $r->status, $cause);
        }
        // a system SQLSTATE supplied by the gateway disqualifies the marker outright
        $e = new GatewayException('x', 500, null, ['error' => 'query_failed', 'sqlstate' => '23514', 'cause' => '[public:x:404] attacker text']);
        $this->assertStringNotContainsString('attacker', DbErrorMapper::resolve($e)->message);
    }

    public function testRealMarkerOnThePrimaryLineStillWorks(): void
    {
        $r = DbErrorMapper::resolve($this->gw('[public:out_of_stock:409] Sorry, sold out | DETAIL: [public:x:404] attacker text'));
        $this->assertSame(409, $r->status);
        $this->assertSame('Sorry, sold out', $r->message, 'text is bounded by the cause separators');
        $this->assertSame('out_of_stock', $r->errorCode);

        $r = DbErrorMapper::resolve($this->gw('[public:no_status] hello'));
        $this->assertSame(400, $r->status);
        $this->assertSame('hello', $r->message);
    }

    public function testDriverWrappedTextIsAlsoPrimaryLineOnly(): void
    {
        // pdo / pg_last_error style wrapping
        $raw = "SQLSTATE[P0001]: Raise exception: 7 ERROR:  [public:gone:410] It is gone\nCONTEXT:  PL/pgSQL function f() line 3 at RAISE\nDETAIL: [public:x:404] attacker text";
        $e = DbTransportException::fromDriverFailure('Direct database function call failed', new \RuntimeException($raw), false);
        $r = DbErrorMapper::resolve($e);
        $this->assertSame(410, $r->status);
        $this->assertSame('It is gone', $r->message);

        $raw = "SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation \"users\" violates check constraint \"c\"\nDETAIL:  Failing row contains ([public:x:404] attacker text, 1).";
        $e = DbTransportException::fromDriverFailure('Direct database function call failed', new \RuntimeException($raw), false);
        [$status, $message] = DbErrorMapper::classify($e);
        $this->assertSame(422, $status);
        $this->assertStringNotContainsString('attacker', $message);
    }

    public function testLintFlagsValueInterpolationInAPublicRaise(): void
    {
        $bad = "CREATE FUNCTION f(p text) RETURNS void AS \$\$\nBEGIN\n    RAISE EXCEPTION '[public:x:409] user % exists', p;\nEND;\n\$\$ LANGUAGE plpgsql;\n";
        $this->assertSame([3], PublicRaiseLint::scan($bad));
        $this->assertSame([1], PublicRaiseLint::scan("RAISE '[public] hi %', v;"));

        $good = "RAISE EXCEPTION '[public:x:409] already exists';\nRAISE EXCEPTION '[public:x:409] 100%% sure';\nRAISE EXCEPTION 'slug_taken: %', p;\nRAISE EXCEPTION 'plain % value', p;";
        $this->assertSame([], PublicRaiseLint::scan($good), 'only [public..] messages are linted; %% is a literal percent');
    }

    public function testBundledFixturesAreLintClean(): void
    {
        $this->assertSame([], PublicRaiseLint::scanDirectory(__DIR__ . '/../E2E/fixtures/app'));
        $this->assertSame([], PublicRaiseLint::scanDirectory(dirname(__DIR__, 2) . '/src/Templates'));
    }
}
