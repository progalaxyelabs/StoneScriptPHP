<?php

/**
 * php stone auth:check-origins
 *
 * Pre-flight for the builtin Google OAuth popup. The popup posts the sign-in result (tokens) ONLY to allowed
 * origins; this lists the configured ones (ALLOWED_ORIGINS / src/config/allowed-origins.php) and exits 1 when there
 * are none. An empty list works only if the sign-in page is served from the API's own origin; any other site
 * (e.g. an Angular app on its own host) would get no result. Run it in the deploy pipeline. Exit: 0 ok, 1 empty.
 */

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('CONFIG_PATH') && is_dir(ROOT_PATH . 'src' . DIRECTORY_SEPARATOR . 'config')) {
    define('CONFIG_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);
}
require_once rtrim(ROOT_PATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use StoneScriptPHP\Auth\BuiltinOAuth\OpenerOrigins;

$report = OpenerOrigins::report();
foreach ($report['origins'] as $origin) {
    echo "  allowed  $origin\n";
}
if (!$report['ok']) {
    fwrite(STDERR, "FAIL: " . $report['message'] . "\n");
    exit(1);
}
echo "OK: " . $report['message'] . "\n";
exit(0);
