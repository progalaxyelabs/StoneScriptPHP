<?php

namespace StoneScriptPHP\Auth\BuiltinOAuth;

/**
 * App-supplied hook that turns a verified Google identity into the local
 * account record (DB-specific upsert logic — which table, which columns,
 * multi-tenant or not — stays out of the framework). Implement this once per
 * app and pass it to GoogleOAuthRoutes::register().
 *
 * IMPORTANT — the returned array is NOT used verbatim as the login-user
 * payload anymore (that was the R1 bug: a resolver could freely shape the
 * JWT claims / client `user` object, and a misnamed field like `name`
 * instead of `display_name` would silently ship a broken contract).
 * GoogleOAuthCallbackRoute now converts this return value through
 * LoginUser::fromResolverArray() — the ONE canonical serializer — before it
 * ever becomes a token claim or client payload. See LoginUser.php.
 */
interface GoogleOAuthUserResolver
{
    /**
     * @param array{sub:string,email:?string,email_verified:bool,name:?string,picture:?string} $profile
     *   Fields read from Google's VERIFIED ID-token payload only (see
     *   GoogleOAuthCallbackRoute — this is never client-supplied data).
     * @return array{
     *   user_id: string|int,
     *   identity_id?: string|int,
     *   email: string,
     *   display_name: string,
     *   is_email_verified?: bool,
     *   photo_url?: ?string,
     *   extra_claims?: array<string,mixed>
     * } The local account's identity fields. `email` and `display_name` are
     *   REQUIRED and must be non-empty — LoginUser::fromResolverArray()
     *   throws \RuntimeException otherwise, which the callback route bridges
     *   as `oauth_error` and mints NO token. Any other top-level key not
     *   listed above is treated as an extra JWT claim (e.g. `role`, `plan`)
     *   unless `extra_claims` is supplied explicitly. Do NOT return a `name`
     *   key expecting it to become the display name — only `display_name` is
     *   read; the serializer sets a `name` alias itself for legacy readers.
     * @throws \Exception to abort the login (surfaced to the user as a
     *   generic "could not sign you in" — throw \RuntimeException with a
     *   log-worthy message, not one meant for the end user).
     */
    public function resolve(array $profile): array;
}
