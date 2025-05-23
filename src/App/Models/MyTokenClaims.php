<?php

namespace App\Models;

class MyTokenClaims
{
    public string $iss = '';
    public int $iat = 0;
    public int $exp = 0;
    public int $user_id = 0;

    public static function fromDecodedToken($payload): MyTokenClaims
    {
        $claims = new MyTokenClaims();
        $claims->iss = $payload->iss ?? '';
        $claims->iat = $payload->iat ?? 0;
        $claims->exp = $payload->exp ?? 0;
        $claims->user_id = $payload->user_id ?? '';
        return $claims;
    }
}
