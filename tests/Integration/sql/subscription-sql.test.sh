#!/usr/bin/env bash
# Real-Postgres check of sub_get_status / sub_activate (no mocks). Needs docker + postgres:16-alpine.
# Run: bash tests/Integration/sql/subscription-sql.test.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
S="$ROOT/src/Subscriptions/Schema"
C="ssp-subsql-$$"
docker run -d --rm --name "$C" -e POSTGRES_PASSWORD=x postgres:16-alpine >/dev/null
trap 'docker stop "$C" >/dev/null 2>&1 || true' EXIT
for i in $(seq 1 40); do docker exec "$C" pg_isready -U postgres >/dev/null 2>&1 && break; sleep 1; done
sleep 2
q() { docker exec -i "$C" psql -U postgres -v ON_ERROR_STOP=1 -tA "$@"; }
for f in tables/sub_010_subscription_plans.pgsql tables/sub_011_subscriptions.pgsql tables/sub_012_subscription_payments.pgsql functions/sub_get_status.pgsql functions/sub_activate.pgsql; do
  q < "$S/$f" >/dev/null
done
fail=0
check() { if [ "$2" != "$3" ]; then echo "FAIL: $1 (got '$2', want '$3')"; fail=1; else echo "ok - $1"; fi; }
ins() { q -c "INSERT INTO subscriptions(platform_code,tenant_id,plan_code,status,expires_at) VALUES ('p','$1','pro','$2', NOW() + interval '$3');" >/dev/null; }
active() { q -c "SELECT (sub_get_status('$1')->>'is_active')"; }

# W2: is_active rule
ins t_trial   trial      '10 days';  check "trial future is active"        "$(active t_trial)" true
ins t_active  active     '10 days';  check "active future is active"       "$(active t_active)" true
ins t_cancel  cancelled  '10 days';  check "cancelled future stays active" "$(active t_cancel)" true
ins t_susp    suspended  '10 days';  check "suspended never active"        "$(active t_susp)" false
ins t_ref     refunded   '10 days';  check "refunded never active"         "$(active t_ref)" false
ins t_cb      chargeback '10 days';  check "chargeback never active"       "$(active t_cb)" false
ins t_past    active     '-1 day';   check "active past is inactive"       "$(active t_past)" false
ins t_cpast   cancelled  '-1 day';   check "cancelled past is inactive"    "$(active t_cpast)" false

# W1: renewal stacking
days() { q -c "SELECT ROUND(EXTRACT(EPOCH FROM ((sub_activate('p','$1','pro',30)->>'expires_at')::timestamptz - NOW()))/86400)"; }
check "active renewal stacks (10+30)"                 "$(days t_active)" 40
check "cancelled-at-period-end renewal stacks (10+30)" "$(days t_cancel)" 40
check "trial renewal restarts from now (30)"          "$(days t_trial)" 30
check "expired renewal restarts from now (30)"        "$(days t_past)" 30
check "suspended renewal restarts from now (30)"      "$(days t_susp)" 30
check "after renewal cancelled row is active status"  "$(q -c "SELECT status FROM subscriptions WHERE tenant_id='t_cancel'")" active
check "a payment renewal reactivates a suspended row (status becomes active)" "$(q -c "SELECT status FROM subscriptions WHERE tenant_id='t_susp'")" active
exit $fail
