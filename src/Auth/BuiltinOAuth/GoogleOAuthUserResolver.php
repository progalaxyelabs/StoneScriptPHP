<?php

namespace StoneScriptPHP\Auth\BuiltinOAuth;

/**
 * App-supplied hook that turns a verified Google identity into the claims
 * this platform's own JWT should carry. Keeps DB-specific upsert logic
 * (which table, which columns, multi-tenant or not) out of the framework —
 * implement this once per app and pass it to GoogleOAuthRoutes::register().
 */
interface GoogleOAuthUserResolver
{
    /**
     * @param array{sub:string,email:?string,email_verified:bool,name:?string,picture:?string} $profile
     *   Fields read from Google's VERIFIED ID-token payload only (see
     *   GoogleOAuthCallbackRoute — this is never client-supplied data).
     * @return array JWT payload claims (e.g. user_id, email, name) — merged
     *   as-is into both the access and refresh tokens minted for this login.
     * @throws \Exception to abort the login (surfaced to the user as a
     *   generic "could not sign you in" — throw \RuntimeException with a
     *   log-worthy message, not one meant for the end user).
     */
    public function resolve(array $profile): array;
}
