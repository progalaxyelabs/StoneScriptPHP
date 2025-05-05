<?php

use App\Routes\GoogleOauthRoute;
use App\Routes\HomeRoute;

return [
    'GET' => [
        '/' => HomeRoute::class,
    ],
    'POST' => [
        '/auth/google' => GoogleOauthRoute::class
    ]
];
