<?php

use App\Routes\HomeRoute;
use App\Routes\OauthGoogleRefreshRoute;
use App\Routes\OauthGoogleVerifyRoute;

return [
    'GET' => [
        '/' => HomeRoute::class,
    ],
    'POST' => [
        '/oauth/google/verify' => OauthGoogleVerifyRoute::class,
        '/oauth/google/refresh' => OauthGoogleRefreshRoute::class
    ]
];
