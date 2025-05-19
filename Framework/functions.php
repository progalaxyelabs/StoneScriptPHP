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
    log_error('res_error: ' . $message);
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

    // $class = get_class();
    $class = 'App\Env';

    // $properties = array_filter(array_keys(get_class_vars($class)), function ($item) {
    //     return ($item !== '_instance');
    // });
    $properties = array_keys(get_class_vars($class));

    // log_debug('env properties are ' . var_export($properties, true));

    // $class = get_class();

    $missing_keys = [];

    $dotenv = parse_ini_file($env_file_path);
    foreach ($properties as $key) {
        if (array_key_exists($key, $dotenv)) {
            Env::$$key = $dotenv[$key];
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



