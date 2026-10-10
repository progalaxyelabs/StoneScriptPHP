#!/usr/bin/env bash
# Runs the refresh-token schema behaviour test against a throwaway postgres:16 container.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
SCHEMA="$ROOT/src/Auth/RefreshTokens/Schema"
NAME="ssp-rt-test-$$"
docker run -d --rm --name "$NAME" -e POSTGRES_PASSWORD=t -e POSTGRES_DB=t postgres:16-alpine >/dev/null
trap 'docker stop "$NAME" >/dev/null 2>&1 || true' EXIT
for i in $(seq 1 30); do docker exec "$NAME" pg_isready -U postgres -d t >/dev/null 2>&1 && break; sleep 1; done
psql_() { docker exec -i "$NAME" psql -v ON_ERROR_STOP=1 -U postgres -d t "$@"; }
for f in "$SCHEMA"/tables/*.pgsql "$SCHEMA"/functions/*.pgsql; do psql_ -q < "$f"; done
# apply twice: the schema must be idempotent
for f in "$SCHEMA"/tables/*.pgsql "$SCHEMA"/functions/*.pgsql; do psql_ -q < "$f"; done
psql_ < "$ROOT/tests/Integration/sql/refresh_tokens_test.sql"
