# Upgrading to 14.2

1. Bump `progalaxyelabs/stonescriptphp` to `^14.2` (this pulls in `progalaxyelabs/stonescriptdb-gateway-client` `^4.1`), then run `composer update`.
2. Make sure `DB_GATEWAY_PLATFORM_TOKEN` is present in the application's environment. The framework now sends it as `Authorization: Bearer <token>` on every gateway request.
3. Run `php stone env:check`; it warns when the variable is missing in gateway mode.

No application code changes are needed. Never log or commit the token.
