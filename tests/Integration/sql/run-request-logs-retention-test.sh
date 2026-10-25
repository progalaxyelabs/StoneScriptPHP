#!/usr/bin/env bash
# Runs the request_logs retention behaviour test against a real PostgreSQL.
# Everything is created as TEMP objects in ONE psql session and vanishes with it: no database or schema is created
# and nothing persists. Point it at any scratch/dev database with REQUEST_LOGS_TEST_PSQL, for example
#   REQUEST_LOGS_TEST_PSQL="psql -h 127.0.0.1 -U postgres -d postgres" ./run-request-logs-retention-test.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
SCHEMA="$ROOT/src/RequestLogging/Schema"
PSQL="${REQUEST_LOGS_TEST_PSQL:-psql -h /var/run/postgresql -d postgres}"
{
  echo "SET search_path = pg_temp, public;"
  cat "$SCHEMA/tables/req_001_request_logs.pgsql"
  cat "$SCHEMA/tables/req_001_request_logs.pgsql"   # idempotent: second apply is a no-op
  cat "$SCHEMA/functions/rl_purge_request_logs.pgsql"
  cat "$SCHEMA/functions/rl_purge_request_logs.pgsql"
  cat "$ROOT/tests/Integration/sql/request_logs_retention_test.sql"
} | $PSQL -v ON_ERROR_STOP=1 -q 2>&1 | grep -v -i -E "collation|^DETAIL|^HINT"
