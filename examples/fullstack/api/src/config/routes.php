<?php

use App\Routes\HomeRoute;

return [
    'GET' => [
        '/' => HomeRoute::class,
    ],
    'POST' => [        '/websites' => \App\Routes\WebsitesRoute::class,

    ]
];
