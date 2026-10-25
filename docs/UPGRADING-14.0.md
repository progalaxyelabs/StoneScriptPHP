# Upgrading to 14.0.0 (PHP 8.5.12 minimum)

14.0.0 is a major release for one reason: **the PHP floor is now `>=8.5.12`** (the PHP2Shell-fixed 8.5; CVE-2026-109618 /
CVE-2026-109619). PHP 8.2, 8.3 and 8.4 are no longer supported. There are no other intentional API or behaviour changes: if your
app runs clean on PHP 8.5.12, the framework upgrade is `composer require progalaxyelabs/stonescriptphp:^14.0`.

## Order of work per app

1. Move the runtime to PHP 8.5.12+ (upstream build, packages.sury.org / ondrej PPA / official `php:8.5` image; a distro package that
   reports an older number is refused, see `UPGRADING-13.2.1.md`). On a shared server run it side by side with the old PHP,
   in its own php-fpm pool and socket, and switch one platform at a time.
2. In the app's `composer.json`: `"php": ">=8.5.12"`, remove or raise any `config.platform.php` pin (a pin of `8.3.x` makes composer
   refuse 14.x), then `composer require progalaxyelabs/stonescriptphp:^14.0` and `composer update progalaxyelabs/stonescriptdb-gateway-client`
   (needs `4.0.1`, which removes a deprecated call; `^4.0` already allows it).
3. Fix what PHP 8.5 reports in YOUR code (below), run the whole suite on 8.5 with `error_reporting=-1`, deploy, restart php-fpm.
4. Recommended server hardening for any PHP-FPM host (the framework does not use upload progress): `session.upload_progress.enabled=0`.

## What the framework itself changed for 8.5

- `curl_close()` removed from every call site (no-op since 8.0, deprecated in 8.5).
- `$http_response_header` replaced by `http_get_last_response_headers()` in the CLI gateway helper.
- `ReflectionProperty/Method::setAccessible()` removed from the test-suite (no-op since 8.1, deprecated in 8.5).
- `StoneScriptPHP\Support\PhpRuntimeFloor` now enforces a single floor (8.5.12) at every boot.
- A regression test (`NoDeprecatedReflectionCallsTest`) fails the build if `setAccessible`, `curl_close` or `$http_response_header`
  come back into `src/` or `cli/`.

## What to look for in your app (PHP 8.5 deprecations that bite)

| 8.5 deprecation | typical fix |
|---|---|
| `curl_close()` | delete the call |
| `ReflectionProperty/Method::setAccessible()` | delete the call (tests mostly) |
| `$http_response_header` after `file_get_contents()` | `http_get_last_response_headers()` |
| non-canonical casts `(integer)` `(boolean)` `(double)` `(binary)`, backtick shell operator, `;` after `case` | use `(int)` `(bool)` `(float)` `(string)`, `shell_exec()`, `:` |
| `null` as array offset, `__sleep()/__wakeup()` | cast the key; use `__serialize()/__unserialize()` |

`php -d error_reporting=-1 -l file.php` finds the compile-time ones; the runtime ones appear as `E_DEPRECATED` in the app log
(the framework logger prints them as `PHP E_DEPRECATED`), so run the full suite once and grep the output.
