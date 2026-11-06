# Upgrading to 14.4.0

Three changes: a framework-owned post-install, a dependency gate, and a fail-closed subscription lookup.

## 1. Replace the inline Composer hooks

In the app's `composer.json`, replace every command in `post-install-cmd` and `post-update-cmd` with one line, and deny Composer plugins:

```json
"scripts": {
    "post-install-cmd": ["@php vendor/progalaxyelabs/stonescriptphp/stone post-install"],
    "post-update-cmd":  ["@php vendor/progalaxyelabs/stonescriptphp/stone post-install"]
},
"config": {
    "allow-plugins": {}
}
```

The vendor path is used on purpose: an app whose root `stone` predates 14.4.0 does not know `post-install` yet, and the first run refreshes it.

Composer runs only the root `composer.json` scripts, never a dependency's own. What remains risky is a Composer plugin (gated only by `allow-plugins`), a package that rewrites files under `vendor/`, and runtime code from a package's autoload `files`.

`stone post-install` stops with exit 1 when the installed framework differs from its release. That check detects accidental damage (a partial install, another package or a manual edit changing framework files). It does not defend against code that deliberately rewrites both the framework and this script; use `deps:check` and a denied plugin list for that.

## 2. Add `stone-packages.json` at the project root

```json
{
  "schema_allowlist": { "progalaxyelabs/stonescriptphp-invoice": "^1.0" },
  "approved": {
    "vendors": ["progalaxyelabs"],
    "packages": ["firebase/php-jwt", "vlucas/phpdotenv"]
  },
  "autoload_files_reviewed": { "vlucas/phpdotenv": "^5.6" },
  "plugins_approved": []
}
```

- `schema_allowlist`: the only packages (besides the framework) whose SQL is staged into `src/postgresql/vendor/`. A package that ships SQL but is not listed produces a notice and nothing is staged. Before 14.4.0 every installed `progalaxyelabs/*` package was staged; add the ones you use.
- `approved`: every installed production package must match a listed package or vendor.
- `autoload_files_reviewed`: packages that declare autoload `files` (run on every request) and that a human has read, with the version range reviewed.

Run `php stone deps:check` (add `--no-audit` offline). It exits 1 on an unapproved package, a known vulnerability, an unreviewed autoload-files package, an unapproved plugin, or a foreign command in the post-install hooks. Run it in CI and before deploy.

## 3. Package SQL layout

Staging reads `vendor/<pkg>/postgresql/main/<bucket>/**/*.pgsql` (basenames unchanged; the `migrations` bucket is namespaced `<pkg-slug>__<name>`). `src/**/Schema/` is still read when a package has no `postgresql/` tree, with a deprecation notice.

## 4. Subscription lookup fails closed

If the subscription lookup errors (gateway down, missing function), `SubscriptionMiddleware` no longer lets the request through. Reads and allow-listed writes work; other writes return 503 `SUBSCRIPTION_LOOKUP_UNAVAILABLE`. Clients should treat that status as retryable. To restore the old behaviour on a development machine only: `'lookup_failure' => 'allow'` (refused when `APP_ENV` is not a development value).
