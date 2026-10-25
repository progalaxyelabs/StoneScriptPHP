# Upgrading to 13.2.1 (PHP security floor)

13.2.1 will not install or boot on a PHP release that is exposed to the unauthenticated PHP RCE CVE-2026-109618 /
CVE-2026-109619 ("PHP2Shell"). Required PHP: **8.2.35+, 8.3.36+, 8.4.27+, or 8.5.12+** (any later line is fine).
No application code changes are needed.

## What to do per app

1. Patch the runtime first (see below), then in the app: `composer require progalaxyelabs/stonescriptphp:^13.2.1`.
2. Put the same constraint in the app's own `composer.json`: `"php": "~8.2.35 || ~8.3.36 || ~8.4.27 || >=8.5.12"`, and remove any
   `config.platform.php` pin older than your patched version (a pin such as `8.3.33` makes composer resolve as if the old PHP
   were installed and then refuse the framework).
3. Restart php-fpm / workers. Check: `php -v`, then `php stone` (prints the framework help, not "REFUSING TO START").

## How it is enforced (two layers, both needed)

| layer | when | what it catches | what it cannot do |
|---|---|---|---|
| `composer.json` `php` constraint | `composer install/update` | every vulnerable version | skipped by `--ignore-platform-reqs`, and not re-checked after deploy |
| `StoneScriptPHP\Support\PhpRuntimeFloor` (autoload) | every boot: php-fpm request, `php stone`, workers | every vulnerable version, even if composer was bypassed or PHP was downgraded later | cannot see a backport (below) |

Composer's own `vendor/composer/platform_check.php` is generated from the *lowest* bound of the constraint only
(`PHP_VERSION_ID >= 80235`), so by itself it would accept 8.3.6 or 8.5.4. That is why the framework adds its own guard.

What an app shows on a bad PHP:
- web: HTTP `503`, `Content-Type: application/json`, `Retry-After`, a deliberately generic body `{"status":"error","message":"Service temporarily unavailable","error_code":"service_unavailable"}` (nothing about PHP, security or versions reaches the visitor). The exact version and CVE ids are written only to the server error log (`[StoneScriptPHP boot] REFUSING TO BOOT: PHP 8.3.6 is below the patched floor ...`). Health checks therefore go red instead of a blank 500.
- CLI (`php stone`, workers): the reason on stderr, exit code 1.

## Distro builds that backport fixes but report an old version (Debian/Ubuntu `8.3.6`, `8.1.2-1ubuntu...`)

**Treated as vulnerable and refused. This is deliberate and permanent.** PHP reports `PHP_VERSION` from the build; a
backported fix keeps the old number, so neither Composer nor a runtime check can tell a patched backport from an unpatched
build without trusting the package changelog. A guard that guesses is worse than one that is strict. Production servers should therefore run
upstream builds (packages.sury.org for Debian, ppa:ondrej/php for Ubuntu) whose version number is truthful.

Debian/Ubuntu quick path (as root, one command per line):

    apt-get update
    apt-get install --only-upgrade php8.3-fpm php8.3-cli php8.3-common
    php -v

(after adding the sury / ondrej repository once). Containers: use the official `php:8.x` image at a patched tag, or build from
upstream packages, never the distro's `php` package.

## Developer workstations and CI images

If your local PHP is still below the floor, update it. As a stop-gap for running the test-suite only, export
`STONESCRIPT_ALLOW_UNPATCHED_PHP=1` in that shell. It prints a warning on every run and is **ignored under every web SAPI**
(php-fpm, cgi, apache, `php -S`), so it can never keep a vulnerable server serving requests. Do not set it in a service
environment, pool config or Dockerfile.
