<?php

namespace App\Models;

class MyTokenClaims {
    public $iss;
    public $iat;
    public $exp;
    public $user_id;  
    
    public static function fromDecodedToken($payload): MyTokenClaims {
        $claims = new MyTokenClaims();
        $claims->iss = $payload->iss ?? '';
        $claims->iat = $payload->iat ?? 0;
        $claims->exp = $payload->exp ?? 0;
        $claims->user_id = $payload->user_id ?? '';
        return $claims;
    }
}