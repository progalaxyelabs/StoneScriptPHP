<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\AuthContext;
use StoneScriptPHP\RequestLogging\FailureText;
use StoneScriptPHP\RequestLogging\PathScrubber;
use StoneScriptPHP\RequestLogging\RequestContext;
use StoneScriptPHP\RequestLogging\RequestLogger;

class PathScrubberTest extends TestCase
{
    protected function setUp(): void { PathScrubber::reset(); RequestLogger::reset(); \StoneScriptPHP\Http\ClientIp::reset(); RequestContext::reset(); AuthContext::clear(); http_response_code(200); }
    protected function tearDown(): void { PathScrubber::reset(); RequestLogger::reset(); \StoneScriptPHP\Http\ClientIp::reset(); RequestContext::reset(); }

    /** @return array<string, array{string,string}> */
    public static function cases(): array
    {
        $m = PathScrubber::MARKER;
        return [
            'invitation token'      => ['/invitations/Zk3pQ9xLm2Vb7Nc4Rt8Yw1HsJd6Fa0Ge', "/invitations/$m"],
            'cancel token base64url' => ['/cancel/aB3_dE6-gH9jK2mN5pQ8sT1v', "/cancel/$m"],
            'hex token'             => ['/reset/0123456789abcdef0123456789abcdef', "/reset/$m"],
            'hex with extension'    => ['/files/0123456789abcdef0123456789abcdef.json', "/files/$m.json"],
            'lowercase run token'   => ['/v/qwertyuiopasdfghjklzxcvbnm', "/v/$m"],
            'numeric id'            => ['/api/workspaces/123/status', '/api/workspaces/123/status'],
            'long numeric'          => ['/api/x/12345678901234567890123', '/api/x/12345678901234567890123'],
            'uuid'                  => ['/api/orders/123e4567-e89b-12d3-a456-426614174000/items', '/api/orders/123e4567-e89b-12d3-a456-426614174000/items'],
            'kebab route words'     => ['/api/workspace-subscriptions-list', '/api/workspace-subscriptions-list'],
            'snake route words'     => ['/api/get_user_notification_prefs', '/api/get_user_notification_prefs'],
            'short ids'             => ['/api/u/ab12cd34', '/api/u/ab12cd34'],
            'root'                  => ['/', '/'],
        ];
    }

    /** @dataProvider cases */
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function test_scrub(string $in, string $out): void
    {
        $this->assertSame($out, PathScrubber::scrub($in));
    }

    public function test_failure_text_path_scrubs_and_still_drops_the_query(): void
    {
        $this->assertSame('/invitations/' . PathScrubber::MARKER, FailureText::path('/invitations/Zk3pQ9xLm2Vb7Nc4Rt8Yw1HsJd6Fa0Ge?x=1#f'));
    }

    public function test_app_extra_pattern_hook_and_invalid_pattern_ignored(): void
    {
        PathScrubber::addPattern('~/promo/[^/]+~');
        PathScrubber::addPattern('~(unterminated');
        $this->assertSame('/api{redacted}/x', PathScrubber::scrub('/api/promo/SUMMER24/x'));
    }

    public function test_config_patterns_reach_the_scrubber_via_arm(): void
    {
        RequestLogger::arm(['request_logging' => ['path_scrub_patterns' => ['~/s/[^/]+~']]], microtime(true));
        $this->assertSame('/a{redacted}', PathScrubber::scrub('/a/s/short'));
    }

    public function test_request_log_row_path_and_referer_never_hold_the_token(): void
    {
        $token = 'Zk3pQ9xLm2Vb7Nc4Rt8Yw1HsJd6Fa0Ge';
        $rows = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = "/invitations/$token?utm=1";
        $_SERVER['HTTP_REFERER'] = "https://app.example.test/cancel/$token?e=a@b.c";
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $r) use (&$rows): void { $rows[] = $r; });
        RequestLogger::persistRequestLog();
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_REFERER']);

        $this->assertCount(1, $rows);
        $this->assertSame('/invitations/' . PathScrubber::MARKER, $rows[0]['path']);
        $this->assertStringNotContainsString($token, json_encode($rows[0]));
        $this->assertSame('https://app.example.test/cancel/' . PathScrubber::MARKER, $rows[0]['referer']);
    }

    public function test_performance_is_trivial(): void
    {
        $t = microtime(true);
        for ($i = 0; $i < 20000; $i++) {
            PathScrubber::scrub('/api/workspaces/123/status');
            PathScrubber::scrub('/invitations/Zk3pQ9xLm2Vb7Nc4Rt8Yw1HsJd6Fa0Ge');
        }
        $this->assertLessThan(1.0, microtime(true) - $t, '40k scrubs in well under a second');
    }
}
