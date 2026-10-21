<?php

declare(strict_types=1);

/**
 * CLI output helpers that never print raw database / gateway error text.
 *
 * A gateway error body carries `cause` (PostgreSQL message, DETAIL, HINT, custom RAISE text) that routinely echoes
 * customer data, and CLI output lands in terminal scrollback, CI logs and container stdout. Bodies are therefore
 * rendered from a closed allow-list of identifier-shaped fields; everything else is dropped.
 */

if (!function_exists('gw_safe_body')) {
    /**
     * Value-free rendering of a gateway response body.
     *
     * @param string|null $body raw HTTP response body
     */
    function gw_safe_body(?string $body): string
    {
        if ($body === null || $body === '') {
            return '';
        }
        $d = json_decode($body, true);
        if (!is_array($d)) {
            return '(non-JSON response body withheld, ' . strlen($body) . ' bytes)';
        }
        $parts = [];
        $ident = '/^[A-Za-z0-9_.\-:]{1,80}$/';
        foreach (['error', 'status', 'job_id', 'database', 'database_name', 'function', 'migration', 'sqlstate', 'schema', 'table', 'column', 'constraint', 'correlation_id', 'request_id'] as $k) {
            if (isset($d[$k]) && is_string($d[$k]) && preg_match($ident, $d[$k]) === 1) {
                $parts[] = $k . '=' . $d[$k];
            }
        }
        $withheld = isset($d['cause']) || isset($d['message']) || isset($d['detail']);
        $out = implode(' ', $parts);
        if ($withheld) {
            $out .= ($out !== '' ? ' ' : '') . '(error details withheld: they may contain data values)';
        }
        return $out !== '' ? $out : '(response fields withheld)';
    }

    /** Value-free rendering of one migrate-all per-database/aggregate `error` string. */
    function gw_safe_error_text(mixed $text): string
    {
        if (!is_string($text) || $text === '') {
            return 'no error detail';
        }
        $extra = [];
        // Which migration file / function failed is an identifier the operator needs.
        if (preg_match('/^[^:]*?\b(?:Migration|Function deployment) failed in [A-Za-z0-9_$.\-]+: ([A-Za-z0-9_.\-]{1,100}) - /', $text, $m) === 1) {
            $extra[] = 'item=' . $m[1];
        }
        $out = \StoneScriptPHP\Persistence\SafeDbContext::summarize('error', $text);
        return $extra === [] ? $out : $out . ' ' . implode(' ', $extra);
    }

    /** Value-free message for any Throwable (PDOException rebuilt from identifiers; others sanitised). */
    function cli_safe_message(\Throwable $e): string
    {
        return \StoneScriptPHP\Persistence\SafeDbContext::message($e);
    }
}
