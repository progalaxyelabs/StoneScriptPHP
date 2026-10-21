#!/usr/bin/env bash
# Runs the real-gateway E2E suites: PostgreSQL 15 + a prebuilt gateway in containers.
#   E2E_GATEWAY_BIN=/path/to/stonescriptdb-gateway tests/E2E/run-e2e.sh
# Optional:
#   E2E_PHP=...           php command (default: php) - must have the extensions PHPUnit needs
#   E2E_CLIENT_DIR=...    also run the gateway client repo's E2E suite from this checkout
# Exits non-zero unless the E2E tests RAN and passed (a skipped run is a failure here).
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PHP="${E2E_PHP:-php}"
export E2E_WORK_DIR="${E2E_WORK_DIR:-$ROOT/.e2e-work}"
"$HERE/gateway-stack.sh" up > /dev/null || exit 2
trap '"$HERE/gateway-stack.sh" down >/dev/null' EXIT
# shellcheck disable=SC1091
. "$E2E_WORK_DIR/env.sh"
export STONESCRIPT_E2E_REQUIRE=1
cd "$ROOT" || exit 2
OUT="$E2E_WORK_DIR/phpunit.out"
status=0
$PHP vendor/bin/phpunit --testsuite E2E --do-not-cache-result "$@" 2>&1 | tee "$OUT"
[ "${PIPESTATUS[0]}" -eq 0 ] || status=1

if [ -n "${E2E_CLIENT_DIR:-}" ]; then
    # fixture platform for the client suite, registered through the real CLI
    PROJ="$E2E_WORK_DIR/client-project"
    rm -rf -- "$PROJ"
    cp -r "$HERE/fixtures/app" "$PROJ"
    PLATFORM="e2eclient$(date +%s)"
    E2E_PROJECT_ROOT="$PROJ" DB_GATEWAY_URL="$STONESCRIPT_E2E_GATEWAY_URL" DB_GATEWAY_PLATFORM="$PLATFORM" \
        DB_GATEWAY_SCHEMA_NAME=main DB_GATEWAY_ADMIN_TOKEN="$STONESCRIPT_E2E_ADMIN_TOKEN" \
        $PHP "$HERE/run_cli.php" gateway-register-main.php --retry=1 --delay=0 > "$E2E_WORK_DIR/client-register.out" 2>&1 \
        || { echo "run-e2e: could not register the client fixture platform" >&2; cat "$E2E_WORK_DIR/client-register.out" >&2; exit 1; }
    export STONESCRIPT_E2E_PLATFORM="$PLATFORM"
    (cd "$E2E_CLIENT_DIR" && $PHP "$ROOT/vendor/bin/phpunit" --bootstrap "$ROOT/vendor/autoload.php" --do-not-cache-result tests/E2E 2>&1) | tee "$E2E_WORK_DIR/client.out"
    [ "${PIPESTATUS[0]}" -eq 0 ] || status=1
    cat "$E2E_WORK_DIR/client.out" >> "$OUT"
fi

if grep -qE 'Skipped: [1-9]|E2E NOT RUN' "$OUT"; then
    echo "run-e2e: FAILED: E2E tests were skipped" >&2
    exit 1
fi
exit "$status"
