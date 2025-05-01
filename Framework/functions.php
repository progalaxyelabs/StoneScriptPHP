<?php

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
    return new ApiResponse('error', $message);
    if(DEBUG_MODE) {
        return new ApiResponse('error', $message);
    } else {
        return new ApiResponse('error', 'server error.');
    }
}
