<?php
// Child process: a CLI script that has NOT loaded the framework bootstrap, only the guard (and the autoloader if present).
// argv[1] = scenario
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php'; // classes only; no ExceptionHandler registration happens for these constants
require $root . '/cli/helpers/cli-guard.php';
cli_install_guard();

switch ($argv[1] ?? '') {
    case 'warning':
        trigger_error("pg_query(): Query failed: ERROR:  duplicate key value violates unique constraint \"users_email_key\"\nDETAIL:  Key (email)=(jane.sentinel@example.test) already exists. +919876500123", E_USER_WARNING);
        // real libpq failure: the warning text names the unreachable server; keep the sentinel in the conn string
        pg_connect('host=127.0.0.1 port=1 dbname=jane_sentinel_db user=sentineluser connect_timeout=1');
        echo "done\n";
        break;
    case 'uncaught':
        $p = new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "users_email_key"' . "\nDETAIL:  Key (email)=(jane.sentinel@example.test) already exists. hash \$2y\$10\$sentinelhashvalue");
        $p->errorInfo = ['23505', 7, 'x'];
        throw $p;
}
