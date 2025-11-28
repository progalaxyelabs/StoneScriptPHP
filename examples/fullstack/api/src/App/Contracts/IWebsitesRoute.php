<?php

namespace App\Contracts;

use App\DTO\WebsitesRequest;
use App\DTO\WebsitesResponse;

interface IWebsitesRoute
{
    public function execute(WebsitesRequest $request): WebsitesResponse;
}
