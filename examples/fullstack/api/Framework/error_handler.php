<?php

namespace Framework;

function e500($message = 'Server error'): ApiResponse
{
    http_response_code(500);
    return res_error($message);
}

function e405($message = 'Method not allowed'): ApiResponse
{
    http_response_code(405);
    return res_error($message);
}

function e404($message = 'Not found'): ApiResponse
{
    http_response_code(404);
    return res_error($message);
}

function e400($message = 'Bad Request'): ApiResponse
{
    http_response_code(400);
    return res_error($message);
}

function e415($message = 'Unsupported Media Type'): ApiResponse
{
    http_response_code(415);
    return res_error($message);
}
