<?php

namespace Framework;

use Exception;

class InvalidRouteException extends Exception {
    public function __construct()
    {
        parent::__construct('Page not found', 404);
    }
}