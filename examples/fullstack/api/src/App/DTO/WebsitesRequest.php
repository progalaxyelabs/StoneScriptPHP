<?php

namespace App\DTO;

class WebsitesRequest
{
    public function __construct(
        public readonly string $name,
        public readonly string $type, // 'portfolio' | 'business' | 'ecommerce' | 'blog'
        public readonly ?string $userId = null,
    ) {}
}
