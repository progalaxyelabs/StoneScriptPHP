# Distro-patched PHP (14.1.0)

Distros backport the PHP2Shell fix (CVE-2026-109618 / CVE-2026-109619) but keep the old version number (`8.5.4-0ubuntu1.5`
reports `PHP_VERSION` 8.5.4). From 14.1.0 the boot guard (`PhpRuntimeFloor`) accepts such a build in two safe ways. Upstream
builds (sury / ondrej / official `php:8.5.12+` image) are unchanged: judged by `PHP_VERSION`, floor 8.5.12.
Everything else is still refused (CLI: stderr + exit 1; web: generic 503, detail only in the server error log).

## (a) Built-in table (no operator action)
When `PHP_VERSION` is below the floor the guard reads `/etc/os-release` and asks `dpkg-query` for the installed
package (argv-array exec, no shell, 3 s bound, cached per process), requires the package's upstream version to equal the
running `PHP_VERSION`, and compares it with `PhpRuntimeFloor::DISTRO_PATCHED` using `dpkg --compare-versions`. If it is at least
the minimum patched version the process boots and logs one line:
`accepted distro-patched build php8.5-common 8.5.4-0ubuntu1.5 (minimum patched ... on ubuntu:26.04) covering CVE-2026-109618 / CVE-2026-109619.`

Rows shipped: **Ubuntu 26.04 `php8.5-common >= 8.5.4-0ubuntu1.5`**. A row is added only with a primary source in the code comment.
Unknown distro, release, package, missing/blocked `dpkg` (e.g. `open_basedir`, no `proc_open`), or an undecidable compare = refuse.

## (b) Operator acknowledgement (any distro, any SAPI)
Set after reading your distro's changelog / security tracker yourself:

    STONESCRIPT_DISTRO_PATCH_ACK="CVE-2026-109618,CVE-2026-109619;php8.5-common=8.5.4-1+deb13u2"

It matches only if **all** CVE ids the guard protects are listed AND the named package's installed version equals the stated
string exactly. Upgrading/downgrading the package, or a future CVE added to the guard, makes it stop matching and the guard refuses
again. When it applies it logs a loud `WARNING OPERATOR ACKNOWLEDGEMENT in force ...` line on every boot. The framework does
not verify the attestation. For php-fpm pass the variable through the pool (`env[STONESCRIPT_DISTRO_PATCH_ACK] = ...`;
`clear_env` strips it otherwise). Not honoured for lines below 8.5.

The CLI-only `STONESCRIPT_ALLOW_UNPATCHED_PHP=1` developer override is unchanged.

## composer
`composer.json` now requires `php >=8.5` so `composer install` does not refuse a distro build; the runtime guard is the authority
(this replaces the "deliberate and permanent" stance in UPGRADING-13.2.1.md).

## Owner steps to add a distro row
Read the distro's tracker for both CVEs, take the fixed package version for that release, add
`'<id>:<VERSION_ID>' => [805 => ['<package>', '<fixed version>']]` with the URL in a comment, add a test, release a patch.
