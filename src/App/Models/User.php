<?php

namespace App\Models;

class User
{

    public function __construct(
        public readonly int $user_id,
        public readonly string $name,
        public readonly string $photo,
        public readonly string $profile
    ) {}
}
