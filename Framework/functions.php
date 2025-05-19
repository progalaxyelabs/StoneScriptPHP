<?php

use App\Env;
use Framework\ApiResponse;
use Framework\Logger;

function log_debug(string $message)
{
    Logger::get_instance()->log_debug($message);
}

function log_error(string $message)
{
    Logger::get_instance()->log_error($message);
}

function res_ok($data, $message = '') {
    return new ApiResponse('ok', $message, $data);
}

function res_not_ok($message) {
    return new ApiResponse('not ok', $message);
}

function res_error($message) {
    $method_and_url = $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
    log_error('res_error: ' . $method_and_url . ' - ' . $message);
    return new ApiResponse('error', $message);
    
    // if(DEBUG_MODE) {
    //     return new ApiResponse('error', $message);
    // } else {
    //     return new ApiResponse('error', 'server error.');
    // }
}


function init_env()
{
    $env_file_path = ROOT_PATH . '.env';
    if (!file_exists($env_file_path)) {
        $message = 'missing .env file';
        throw new \Exception($message);
    }

    $env_properties = array_keys(get_class_vars(Env::class));

    $missing_keys = [];

    $dotenv_settings = parse_ini_file($env_file_path);
    foreach ($env_properties as $key) {
        if (array_key_exists($key, $dotenv_settings)) {
            Env::$$key = $dotenv_settings[$key];
        } else {
            log_debug("missing setting in .env file [$key]");
            $missing_keys[] = $key;
        }
    }

    $num_missing_keys = count($missing_keys);
    if ($num_missing_keys > 0) {
        throw new \Exception($num_missing_keys . ' Settings missing in .env file');
    }
}



