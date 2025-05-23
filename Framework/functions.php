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

function res_ok($data, $message = '')
{
    return new ApiResponse('ok', $message, $data);
}

function res_not_ok($message)
{
    return new ApiResponse('not ok', $message);
}

function res_not_authorized($message = 'Not Authorized')
{
    http_response_code(401); // Unauthorized
    log_error('HTTP status code 401: ' .  $message);
    return new ApiResponse('not ok', $message, []);
}

function res_error($message)
{
    $method_and_url = $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
    log_error('res_error: ' . $method_and_url . ' - ' . $message);
    return new ApiResponse('error', $message);
}
