<?php

use App\Env;

$env_file_path = '.env';

if (!file_exists($env_file_path)) {
    file_put_contents($env_file_path, '');
}

include 'src/App/Env.php';
$env_properties = array_keys(get_class_vars('App\Env'));
$dotenv_settings = parse_ini_file($env_file_path);
$missing_settings = [];
foreach ($env_properties as $key) {
    if (!array_key_exists($key, $dotenv_settings)) {
        $val = Env::$$key;
        if ($val === null) {
            $val = '';
        }
        $missing_settings[] = "{$key}={$val}";
    }
}
if ($missing_settings) {
    $file = fopen($env_file_path, 'a');
    $content = "\n" . join("\n", $missing_settings);
    fwrite($file, $content);
    fclose($file);
}
