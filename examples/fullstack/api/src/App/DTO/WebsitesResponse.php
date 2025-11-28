<?php

namespace App\DTO;

class WebsitesResponse
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $status,
        public readonly string $createdAt,
    ) {}
}
