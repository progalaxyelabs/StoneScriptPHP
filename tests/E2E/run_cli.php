<?php
// Runs a framework CLI script the way `php stone` does, against a PROJECT root given by E2E_PROJECT_ROOT.
// argv[1] = cli script (relative to the framework cli/ dir), rest = script args.
$framework = dirname(__DIR__, 2);
$project = rtrim((string) getenv('E2E_PROJECT_ROOT'), '/');
if ($project === '' || !is_dir($project)) {
    fwrite(STDERR, "E2E_PROJECT_ROOT is not set\n");
    exit(2);
}
define('ROOT_PATH', $project . DIRECTORY_SEPARATOR);
define('SRC_PATH', ROOT_PATH . 'src' . DIRECTORY_SEPARATOR);
define('CONFIG_PATH', SRC_PATH . 'config' . DIRECTORY_SEPARATOR);
define('IS_VENDOR_MODE', false);
require $framework . '/vendor/autoload.php';
$script = $framework . '/cli/' . $argv[1];
$argv = array_merge([$script], array_slice($argv, 2));
$_SERVER['argv'] = $argv;
require $script;
