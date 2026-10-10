<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\Auth\Client\AuthServiceClient;
use StoneScriptPHP\Auth\Client\AuthServiceException;
use StoneScriptPHP\Auth\Client\AuthServicePublicException;
use StoneScriptPHP\Auth\ExternalAuth\Routes\BaseExternalAuthRoute;
use StoneScriptPHP\Exceptions\PublicMessage;
use StoneScriptPHP\Logger;

/** Upstream auth-service error text is public ONLY via its structured `public_message`; otherwise generic + correlation id. */
final class AuthServiceErrorPublicityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ssp-asp-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        Logger::get_instance()->configure(false, true, false, $this->dir);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        Logger::get_instance()->configure(false, true, false, null);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        http_response_code(200);
    }

    private function logText(): string
    {
        $o = '';
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            $o .= (string) file_get_contents($f);
        }
        return $o;
    }

    /** Runs the client's real error-body handling against a canned body (file:// yields HTTP code 0, i.e. the non-2xx branch). */
    private function clientThrows(string $body): \Throwable
    {
        $file = $this->dir . '/body.json';
        file_put_contents($file, $body);
        $client = (new \ReflectionClass(new class() extends AuthServiceClient {
            public function __construct()
            {
            }
        }))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(AuthServiceClient::class, 'executeCurl');
        $m->setAccessible(true);
        try {
            $ch = curl_init('file://' . $file);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $m->invoke($client, $ch);
        } catch (\Throwable $e) {
            return $e;
        } finally {
            unlink($file);
        }
        $this->fail('expected an AuthServiceException');
    }

    private function route(): BaseExternalAuthRoute
    {
        return new class() extends BaseExternalAuthRoute {
            public function __construct()
            {
            }

            public function validation_rules(): array
            {
                return [];
            }

            public function process(): \StoneScriptPHP\ApiResponse
            {
                return res_ok([]);
            }

            public function run(callable $c): \StoneScriptPHP\ApiResponse
            {
                return $this->proxyCall($c);
            }
        };
    }

    public function test_structured_public_message_becomes_a_public_exception_with_only_that_text(): void
    {
        $e = $this->clientThrows('{"error":"otp_invalid","message":"row 7 victim@example.com failed","public_message":"That code is incorrect."}');
        $this->assertInstanceOf(AuthServicePublicException::class, $e);
        $this->assertInstanceOf(PublicMessage::class, $e);
        $this->assertSame('That code is incorrect.', $e->getMessage());
        $this->assertStringContainsString('otp_invalid', $e->getPrevious()->getMessage(), 'upstream detail is kept in the cause for the log');
    }

    public function test_upstream_free_text_without_public_message_is_not_public(): void
    {
        foreach (['{"error":"db exploded at victim@example.com"}', '{"message":"internal detail"}', '{"public_message":123}', '{"public_message":"   "}', 'not json'] as $body) {
            $e = $this->clientThrows($body);
            $this->assertInstanceOf(AuthServiceException::class, $e, $body);
            $this->assertNotInstanceOf(PublicMessage::class, $e, $body);
        }
    }

    public function test_public_message_is_cleaned_and_bounded(): void
    {
        $t = AuthServicePublicException::extract(['public_message' => "Line1\n\x00Line2 " . str_repeat('x', 500)]);
        $this->assertStringNotContainsString("\n", $t);
        $this->assertLessThanOrEqual(AuthServicePublicException::MAX_LENGTH, mb_strlen($t));
        $this->assertNull(AuthServicePublicException::extract('x'));
    }

    public function test_route_shows_a_public_message_and_hides_everything_else(): void
    {
        $r = $this->route();

        $res = $r->run(function (): never {
            throw new AuthServicePublicException('That code is incorrect.', 400, new AuthServiceException('Auth service returned HTTP 400: otp_invalid victim@example.com', 400));
        });
        $this->assertSame('That code is incorrect.', $res->message);

        $res = $r->run(function (): never {
            throw new AuthServiceException('Auth service returned HTTP 400: duplicate key Key (email)=(victim@example.com)', 400);
        });
        $this->assertSame('Bad request', $res->message);
        $this->assertStringNotContainsString('victim@example.com', $res->toJson());
        $cid = $res->data['correlation_id'];
        $log = $this->logText();
        $this->assertStringContainsString($cid, $log);
        $this->assertStringNotContainsString('victim@example.com', $log);

        $res = $r->run(function (): never {
            throw new AuthServiceException('Auth service request failed: Connection refused to http://10.0.0.5 (errno: 7)');
        });
        $this->assertSame('Authentication service unavailable', $res->message);
        $this->assertStringNotContainsString('10.0.0.5', $res->toJson());
    }
}
