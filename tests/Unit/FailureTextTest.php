<?php

declare(strict_types=1);

namespace StoneScriptPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoneScriptPHP\RequestLogging\FailureText;
use StoneScriptPHP\RequestLogging\RequestContext;
use StoneScriptPHP\RequestLogging\RequestLogger;

class FailureTextTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::reset();
        RequestLogger::reset();
        http_response_code(200);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/x';
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        RequestLogger::reset();
        if (class_exists(\StoneScriptPHP\Http\ClientIp::class)) {
            \StoneScriptPHP\Http\ClientIp::reset();
        }
        http_response_code(200);
        putenv('STONESCRIPTPHP_LOG_DIR');
    }

    public function test_cut_never_splits_a_multibyte_character(): void
    {
        $in = str_repeat('é', 400); // 2 bytes each
        $out = FailureText::cut($in, 300);
        $this->assertSame(1, preg_match('//u', $out), 'valid UTF-8');
        $this->assertLessThanOrEqual(300, mb_strlen($out));

        RequestContext::captureException(new \RuntimeException(str_repeat('日本語', 200)));
        $msg = (string) RequestContext::getErrorMessage();
        $this->assertSame(1, preg_match('//u', $msg), 'request_logs.error_message must be valid UTF-8 or PostgreSQL rejects the row');
        $this->assertLessThanOrEqual(FailureText::MAX_LENGTH, mb_strlen($msg));
    }

    public function test_handled_note_cut_is_multibyte_safe(): void
    {
        RequestContext::noteHandled('lookup', new \RuntimeException(str_repeat('é', 500)));
        $this->assertSame(1, preg_match('//u', (string) RequestContext::getErrorMessage()));
    }

    public function test_invalid_utf8_input_is_repaired_and_never_voids_the_message(): void
    {
        $bad = "Signature verification failed \xC3\x28 for \xFF\xFE";
        $out = FailureText::safe($bad);
        $this->assertNotSame('', $out);
        $this->assertSame(1, preg_match('//u', $out));
        $this->assertStringContainsString('Signature verification failed', $out);
        $this->assertNotSame('', FailureText::maskPii($bad));
    }

    public function test_safe_is_never_empty_for_non_empty_input(): void
    {
        foreach (["'x'", "\xFF\xFF\xFF", '"a"', '   x   '] as $in) {
            $this->assertNotSame('', FailureText::safe($in), 'input: ' . bin2hex($in));
        }
        $this->assertSame('', FailureText::safe(''));
    }

    public function test_opaque_tokens_vendor_keys_and_tax_ids_are_removed(): void
    {
        $hex = bin2hex(random_bytes(20));            // 40 hex
        $b64 = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
        $in = "refresh $hex and $b64 key sk_live_AbCdEf123456 and rzp_test_Zz9988xxyy gstin 29ABCDE1234F1Z5 pan ABCDE1234F end";
        $out = FailureText::safe($in);
        foreach ([$hex, $b64, 'sk_live_AbCdEf', 'rzp_test_Zz', '29ABCDE1234F1Z5', 'ABCDE1234F'] as $needle) {
            $this->assertStringNotContainsString($needle, $out);
        }
        $this->assertStringContainsString('end', $out);
        $this->assertStringNotContainsString('ABCDE1234F', FailureText::maskPii('PAN ABCDE1234F'));
        $this->assertStringNotContainsString('29ABCDE1234F1Z5', FailureText::maskPii('GST 29ABCDE1234F1Z5'));
    }

    public function test_path_only_query_strings_are_removed(): void
    {
        $out = FailureText::maskPii('failed for /portal/x?email=a@b.com&name=Jane Doe now');
        $this->assertStringNotContainsString('a@b.com', $out);
        $this->assertStringNotContainsString('Jane', $out);
        $this->assertStringContainsString('/portal/x', $out);
        $this->assertSame('/portal/x', FailureText::path('/portal/x?email=a@b.com&name=Jane'));
        $this->assertSame('/', FailureText::path('?x=1'));
    }

    public function test_correlation_ids_uuids_and_dates_are_kept(): void
    {
        $in = 'Request failed [correlation_id=3855208bc350, HTTP 500] tenant 123e4567-e89b-12d3-a456-426614174000 on 2026-10-25 06:38:32.409428';
        $out = FailureText::maskPii($in);
        $this->assertStringContainsString('correlation_id=3855208bc350', $out);
        $this->assertStringContainsString('123e4567-e89b-12d3-a456-426614174000', $out);
        $this->assertStringContainsString('2026-10-25', $out);
        $this->assertStringNotContainsString('[number]', $out);
        $this->assertStringContainsString('[number]', FailureText::maskPii('call +91 98765 43210'));
    }

    public function test_control_characters_are_flattened(): void
    {
        $this->assertSame('a b c', FailureText::oneLine("a\r\nb\tc"));
        $this->assertStringNotContainsString("\n", FailureText::safe("x\nERROR forged"));
    }

    public function test_res_error_and_log_request_do_not_log_the_query_string(): void
    {
        $dir = sys_get_temp_dir() . '/ssp-ft-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($dir, 0755, true);
        putenv('STONESCRIPTPHP_LOG_DIR=' . $dir);
        $_SERVER['REQUEST_URI'] = "/x?email=jane.doe@example.test&name=Jane\nERROR forged";
        try {
            res_error('boom');
            log_request('GET', $_SERVER['REQUEST_URI'], 500, 1.0);
            $log = '';
            foreach (glob($dir . '/*') ?: [] as $f) { $log .= (string) file_get_contents($f); }
            $this->assertStringContainsString('res_error: GET /x', $log);
            $this->assertStringNotContainsString('jane.doe', $log);
            $this->assertStringNotContainsString('Jane', $log);
            $this->assertStringNotContainsString('forged', $log);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($dir);
        }
    }

    public function test_a_5xx_with_only_a_handled_note_keeps_the_handled_class(): void
    {
        RequestContext::noteHandled('subscription-lookup', new \LogicException('lookup failed'));
        http_response_code(503);
        $row = [];
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $r) use (&$row): void { $row = $r; });
        RequestLogger::persistRequestLog();
        $this->assertSame(\LogicException::class, $row['error_class']);
        $this->assertStringStartsWith('[handled:subscription-lookup]', $row['error_message']);
    }

    public function test_request_log_row_text_columns_are_valid_utf8(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = str_repeat('é', 800) . "\xFF";
        $_SERVER['HTTP_REFERER'] = 'https://site.example/page?email=a@b.com';
        $row = [];
        RequestLogger::arm([], microtime(true));
        RequestLogger::setWriteAdapter(function (array $r) use (&$row): void { $row = $r; });
        RequestLogger::persistRequestLog();
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_REFERER']);
        $this->assertSame(1, preg_match('//u', $row['user_agent']));
        $this->assertSame('https://site.example/page', $row['referer']);
    }
}
