#!/usr/bin/env bash
# Brings up a REAL PostgreSQL 15 and a REAL StoneScriptDB gateway (prebuilt binary) in containers, for the E2E suite.
#
#   tests/E2E/gateway-stack.sh up     # writes the env file and prints "export ..." lines
#   tests/E2E/gateway-stack.sh down
#
# Required env for `up`:
#   E2E_GATEWAY_BIN   path to a glibc (ubuntu 22.04) stonescriptdb-gateway release binary
# Optional env:
#   E2E_WORK_DIR      scratch dir for gateway data/logs/env file (default: $PWD/.e2e-work)
#   E2E_PG_IMAGE      default postgres:15-alpine
#   E2E_RUN_IMAGE     image that runs the binary (default ubuntu:22.04)
#   E2E_PG_PORT / E2E_GW_PORT   default 55433 / 59000
#
# Everything runs niced/limited and only on 127.0.0.1. Nothing outside the two named containers is touched.
set -eu
cmd="${1:-}"
PG_NAME="${E2E_PG_NAME:-stonescript-e2e-pg}"
GW_NAME="${E2E_GW_NAME:-stonescript-e2e-gw}"
PG_PORT="${E2E_PG_PORT:-55433}"
GW_PORT="${E2E_GW_PORT:-59000}"
WORK="${E2E_WORK_DIR:-$PWD/.e2e-work}"
PG_IMAGE="${E2E_PG_IMAGE:-postgres:15-alpine}"
RUN_IMAGE="${E2E_RUN_IMAGE:-ubuntu:22.04}"
TOKEN="${E2E_ADMIN_TOKEN:-e2e-admin-token-not-a-secret}"

case "$cmd" in
  up)
    : "${E2E_GATEWAY_BIN:?set E2E_GATEWAY_BIN to a built stonescriptdb-gateway binary}"
    [ -x "$E2E_GATEWAY_BIN" ] || { echo "E2E_GATEWAY_BIN is not executable: $E2E_GATEWAY_BIN" >&2; exit 2; }
    mkdir -p "$WORK/data" "$WORK/logs"
    docker rm -f "$PG_NAME" "$GW_NAME" >/dev/null 2>&1 || true
    docker run -d --name "$PG_NAME" --memory 1g --cpus 2 -p "127.0.0.1:$PG_PORT:5432" \
        -e POSTGRES_USER=gwadmin -e POSTGRES_HOST_AUTH_METHOD=trust "$PG_IMAGE" -c fsync=off >/dev/null
    for _ in $(seq 1 60); do docker exec "$PG_NAME" pg_isready -U gwadmin >/dev/null 2>&1 && break; sleep 1; done
    sleep 2
    docker exec "$PG_NAME" psql -U gwadmin -d postgres -v ON_ERROR_STOP=1 -q \
        -c "CREATE ROLE gwtest LOGIN CREATEDB NOSUPERUSER NOCREATEROLE NOREPLICATION" \
        -c "CREATE ROLE gwplat LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION" \
        -c "GRANT gwtest TO gwplat" \
        -c "CREATE DATABASE stonescriptdb_gateway_admin OWNER gwtest"
    cp "$E2E_GATEWAY_BIN" "$WORK/gateway-bin"
    docker run -d --name "$GW_NAME" --network host --memory 1g --cpus 2 \
        -v "$WORK/gateway-bin:/gateway:ro" -v "$WORK/data:/data" -v "$WORK/logs:/logs" \
        -e DATABASE_URL="postgres://gwtest@127.0.0.1:$PG_PORT/postgres" \
        -e GATEWAY_HOST=127.0.0.1 -e GATEWAY_PORT="$GW_PORT" -e ADMIN_TOKEN="$TOKEN" \
        -e ALLOWED_ADMIN_IPS=127.0.0.1 -e DATA_DIR=/data -e LOG_DIR=/logs -e RUST_LOG=info \
        "$RUN_IMAGE" /gateway >/dev/null
    for _ in $(seq 1 60); do
        curl -fsS "http://127.0.0.1:$GW_PORT/health" >/dev/null 2>&1 && break
        sleep 1
    done
    curl -fsS "http://127.0.0.1:$GW_PORT/health" >/dev/null || { docker logs "$GW_NAME" 2>&1 | tail -20 >&2; echo "gateway did not become healthy" >&2; exit 2; }
    {
      echo "export STONESCRIPT_E2E_GATEWAY_URL=http://127.0.0.1:$GW_PORT"
      echo "export STONESCRIPT_E2E_ADMIN_TOKEN=$TOKEN"
      echo "export STONESCRIPT_E2E_GATEWAY_LOG_DIR=$WORK/logs"
    } | tee "$WORK/env.sh"
    ;;
  down)
    docker rm -f "$GW_NAME" "$PG_NAME" >/dev/null 2>&1 || true
    echo "e2e stack removed"
    ;;
  *)
    echo "usage: $0 up|down" >&2; exit 64 ;;
esac
