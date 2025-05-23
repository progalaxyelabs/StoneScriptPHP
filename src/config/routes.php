<?php

use App\Routes\HomeRoute;
use App\Routes\OauthGoogleVerifyRoute;
use App\Routes\RenewAccessTokenRoute;

return [
    'GET' => [
        '/' => HomeRoute::class,
    ],
    'POST' => [
        '/oauth/google/verify' => OauthGoogleVerifyRoute::class,
        '/auth/refresh' => RenewAccessTokenRoute::class
    ]
];
