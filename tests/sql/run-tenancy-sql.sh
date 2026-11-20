#!/usr/bin/env bash
# Runs the generated tenancy SQL (src/Templates/Tenancy) against a REAL PostgreSQL in the gateway deploy order
# (functions first, then migrations, then table sync) and checks behaviour:
#   A. tenant DB, fresh (the generated users table)
#   B. tenant DB, an EXISTING users table (id UUID PK, no default) + the users_expand_identity migration, run twice
#   B2. the expand migration refuses duplicate identity_id values, naming them
#   C. main DB: tenancy functions, status vocabulary, memberships, last-owner rule, builtin ledger, platform slug rule
#   D. main DB, an EXISTING tenants/tenant_memberships (old db_status): the core migration, run twice
# Needs docker and the postgres:16-alpine image (local). Exit 0 = all pass, 1 = a check failed, 77 = docker/image missing.
set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
T="$HERE/../../src/Templates/Tenancy"
NAME="ssp-tenancy-sql-$$"

command -v docker >/dev/null 2>&1 || { echo "SKIP: docker not available"; exit 77; }
docker image inspect postgres:16-alpine >/dev/null 2>&1 || { echo "SKIP: postgres:16-alpine image not available"; exit 77; }

docker run -d --name "$NAME" -e POSTGRES_PASSWORD=t postgres:16-alpine >/dev/null || { echo "SKIP: cannot start postgres"; exit 77; }
trap 'docker rm -f "$NAME" >/dev/null 2>&1' EXIT
for _ in $(seq 1 60); do docker exec "$NAME" pg_isready -U postgres >/dev/null 2>&1 && break; sleep 1; done
sleep 2

FAILS=0
sql() { local db="$1"; shift; docker exec -i -e PGOPTIONS='-c client_min_messages=warning' "$NAME" psql -U postgres -d "$db" -v ON_ERROR_STOP=1 -q "$@"; }
val() { sql "$1" -tA -c "$2" 2>&1; }
mkdb() { docker exec "$NAME" psql -U postgres -q -c "CREATE DATABASE $1" >/dev/null; }
load() { sql "$1" < "$2" >/dev/null || { echo "FAIL: loading $2"; FAILS=$((FAILS + 1)); }; }
expect() { # description expected actual
  if [ "$2" = "$3" ]; then echo "ok   - $1"; else echo "FAIL - $1: expected [$2] got [$3]"; FAILS=$((FAILS + 1)); fi; }
expect_error() { # description db sql expected-substring
  local out; out="$(sql "$2" -tA -c "$3" 2>&1)"; local rc=$?
  if [ $rc -ne 0 ] && printf '%s' "$out" | grep -q "$4"; then echo "ok   - $1"; else echo "FAIL - $1: expected an error containing [$4], rc=$rc out=[$out]"; FAILS=$((FAILS + 1)); fi; }

TF="$T/tenant/functions"
MF="$T/main/functions"
I1="11111111-1111-4111-8111-111111111111"
I2="22222222-2222-4222-8222-222222222222"
I3="33333333-3333-4333-8333-333333333333"

# ---------------------------------------------------------------- A. tenant, fresh
mkdb tenant_fresh
for f in tenant_user_upsert tenant_user_assign_role tenant_get_identity_role_privileges tenant_seed_privileges tenant_missing_privileges tenant_role_privilege_counts tenant_identities_with_access; do load tenant_fresh "$TF/$f.pgsql.template"; done
for t in users user_roles user_role_privileges; do load tenant_fresh "$T/tenant/tables/$t.pgsql.template"; done
expect "A: upsert creates the user with id = identity" "$I1" "$(val tenant_fresh "SELECT o_id FROM tenant_user_upsert('$I1', 'Owner')")"
expect "A: upsert is idempotent" "1" "$(val tenant_fresh "SELECT o_id FROM tenant_user_upsert('$I1', NULL)" >/dev/null; val tenant_fresh "SELECT count(*) FROM users")"
sql tenant_fresh -q -c "SELECT tenant_user_assign_role((SELECT id FROM users WHERE identity_id='$I1'), 'owner', NULL)" >/dev/null
SEED='{"owner":["tenant.manage","sales.create"],"cashier":["sales.create"]}'
expect "A: seed returns the owner privilege count" "2" "$(val tenant_fresh "SELECT o_privilege_count FROM tenant_seed_privileges('$SEED', 'owner')")"
expect "A: seeding twice changes nothing" "3" "$(val tenant_fresh "SELECT o_privilege_count + 1 FROM tenant_seed_privileges('$SEED', 'owner')" >/dev/null; val tenant_fresh "SELECT count(*) FROM user_role_privileges")"
expect "A: the role read returns the owner's privileges" "2" "$(val tenant_fresh "SELECT count(*) FROM tenant_get_identity_role_privileges('$I1') WHERE o_role_code='owner' AND o_privilege_code IS NOT NULL")"
sql tenant_fresh -q -c "SELECT tenant_user_assign_role((SELECT id FROM users WHERE identity_id='$I1'), 'ghost', NULL)" >/dev/null
expect "A: a role without privileges is reported once with a NULL privilege" "1" "$(val tenant_fresh "SELECT count(*) FROM tenant_get_identity_role_privileges('$I1') WHERE o_role_code='ghost' AND o_privilege_code IS NULL")"
expect "A: identities with access: the owner holds a role that has privileges" "$I1" "$(val tenant_fresh "SELECT o_identity_id FROM tenant_identities_with_access()")"
expect "A: a suspended user has no roles" "0" "$(sql tenant_fresh -q -c "UPDATE users SET status='suspended'" >/dev/null; val tenant_fresh "SELECT count(*) FROM tenant_get_identity_role_privileges('$I1')")"
expect_error "A: a bad role code in the seed is refused" tenant_fresh "SELECT * FROM tenant_seed_privileges('{\"Own er\":[\"x\"]}', 'owner')" "invalid_privilege_seed"
expect_error "A: a bad privilege code is refused" tenant_fresh "SELECT * FROM tenant_seed_privileges('{\"owner\":[\"a b\"]}', 'owner')" "invalid_privilege_seed"
expect "A: a suspended user is no longer an identity with access" "0" "$(val tenant_fresh "SELECT count(*) FROM tenant_identities_with_access()")"
expect "A: role counts: owner has 2 privileges and 1 user; ghost has none" "cashier|1|0;ghost|0|1;owner|2|1" "$(val tenant_fresh "SELECT string_agg(o_role_code || '|' || o_privilege_count || '|' || o_user_count, ';' ORDER BY o_role_code) FROM tenant_role_privilege_counts()")"
expect "A: nothing of the seed is missing once it is applied" "0" "$(val tenant_fresh "SELECT count(*) FROM tenant_missing_privileges('$SEED')")"
expect "A: a new privilege in the seed is reported missing (read-only)" "cashier|refunds.create" "$(val tenant_fresh "SELECT o_role_code || '|' || o_privilege_code FROM tenant_missing_privileges('{\"cashier\":[\"sales.create\",\"refunds.create\"]}')")"
expect "A: the dry-run function wrote nothing" "3" "$(val tenant_fresh "SELECT count(*) FROM user_role_privileges")"
expect_error "A: a bad role code on assign is refused" tenant_fresh "SELECT tenant_user_assign_role((SELECT id FROM users LIMIT 1), 'Owner; x', NULL)" "invalid_role_code"

# ---------------------------------------------------------------- B. tenant, existing users (id is the identity, no default)
mkdb tenant_legacy
for f in tenant_user_upsert tenant_user_assign_role tenant_get_identity_role_privileges tenant_seed_privileges; do load tenant_legacy "$TF/$f.pgsql.template"; done
sql tenant_legacy -q -c "CREATE TABLE users (id UUID PRIMARY KEY, email TEXT); INSERT INTO users (id, email) VALUES ('$I1', 'old@example.com');" >/dev/null
for t in user_roles user_role_privileges; do load tenant_legacy "$T/tenant/tables/$t.pgsql.template"; done
MIG="$T/tenant/migrations/users_expand_identity.pgsql.template"
load tenant_legacy "$MIG"; load tenant_legacy "$MIG"
expect "B: identity_id is backfilled from id" "$I1" "$(val tenant_legacy "SELECT identity_id FROM users WHERE id='$I1'")"
expect "B: existing rows become active" "active" "$(val tenant_legacy "SELECT status FROM users WHERE id='$I1'")"
expect "B: the expand migration is idempotent (one unique constraint)" "1" "$(val tenant_legacy "SELECT count(*) FROM pg_constraint WHERE conname='users_identity_id_key'")"
expect "B: upsert of the existing identity returns its id and adds no row" "$I1" "$(val tenant_legacy "SELECT o_id FROM tenant_user_upsert('$I1', 'Name')")"
expect "B: upsert of a NEW identity works on an id column with no default" "$I2" "$(val tenant_legacy "SELECT o_id FROM tenant_user_upsert('$I2', 'New')")"
expect "B: two users, no duplicates" "2" "$(val tenant_legacy "SELECT count(*) FROM users")"
expect "B: the old column is kept" "old@example.com" "$(val tenant_legacy "SELECT email FROM users WHERE id='$I1'")"
sql tenant_legacy -q -c "SELECT tenant_user_assign_role('$I2', 'owner', NULL)" >/dev/null
sql tenant_legacy -q -c "SELECT * FROM tenant_seed_privileges('{\"owner\":[\"a.b\"]}', 'owner')" >/dev/null
expect "B: the role read works on the expanded table" "a.b" "$(val tenant_legacy "SELECT o_privilege_code FROM tenant_get_identity_role_privileges('$I2')")"

# ---------------------------------------------------------------- B2. duplicate identity_id refused, naming them
mkdb tenant_dups
sql tenant_dups -q -c "CREATE TABLE users (id UUID PRIMARY KEY, identity_id UUID); INSERT INTO users VALUES ('$I1','$I3'), ('$I2','$I3');" >/dev/null
OUT="$(sql tenant_dups < "$MIG" 2>&1)"; RC=$?
if [ $RC -ne 0 ] && printf '%s' "$OUT" | grep -q "duplicate users.identity_id" && printf '%s' "$OUT" | grep -q "$I3"; then echo "ok   - B2: duplicate identity_id values stop the migration and are named"; else echo "FAIL - B2: rc=$RC out=[$OUT]"; FAILS=$((FAILS + 1)); fi
expect "B2: nothing was tightened" "0" "$(val tenant_dups "SELECT count(*) FROM pg_constraint WHERE conname='users_identity_id_key'")"

# ---------------------------------------------------------------- C. main, fresh
mkdb main_fresh
for f in platform_get platform_upsert tenancy_get_tenant_status tenancy_upsert_tenant tenancy_set_tenant_status tenancy_create_membership tenancy_get_identity_memberships tenancy_find_membership tenancy_set_membership_status tenancy_find_request tenancy_save_request tenancy_list_tenants tenancy_mirror_membership_status tenancy_mirror_drift tenancy_list_active_member_identities; do load main_fresh "$MF/$f.pgsql.template"; done
load main_fresh "$T/main/migrations/tenancy_core.pgsql.template"
for t in platform tenants tenant_memberships identities tenant_provision_requests tenancy_mirror_drift; do load main_fresh "$T/main/tables/$t.pgsql.template"; done
load main_fresh "$T/main/migrations/tenancy_core.pgsql.template"
TN="aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"; M1="bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"; M2="cccccccc-cccc-4ccc-8ccc-cccccccccccc"; M3="dddddddd-dddd-4ddd-8ddd-dddddddddddd"
expect "C: a new tenant starts provisioning" "provisioning" "$(val main_fresh "SELECT o_status FROM tenancy_upsert_tenant('$TN', 'Acme', NULL, 'acme_tenant_x', '$I1')")"
expect_error "C: a different tenant_db_name for the same tenant is refused" main_fresh "SELECT * FROM tenancy_upsert_tenant('$TN', 'Acme', NULL, 'other_db', '$I1')" "tenant_db_name_mismatch"
sql main_fresh -q -c "SELECT tenancy_set_tenant_status('$TN', 'provisioning_failed')" >/dev/null
expect "C: a retry moves provisioning_failed back to provisioning" "provisioning" "$(val main_fresh "SELECT o_status FROM tenancy_upsert_tenant('$TN', 'Acme', NULL, 'acme_tenant_x', '$I1')")"
expect_error "C: an unknown status is refused" main_fresh "SELECT tenancy_set_tenant_status('$TN', 'active')" "invalid_tenant_provisioning_status"
expect "C: the membership id is the authority's" "$M1" "$(val main_fresh "SELECT o_id FROM tenancy_create_membership('$M1', '$TN', '$I1', true, NULL)")"
expect "C: a second call returns the EXISTING id" "$M1" "$(val main_fresh "SELECT o_id FROM tenancy_create_membership('$M2', '$TN', '$I1', true, NULL)")"
expect "C: not offered while provisioning" "0" "$(val main_fresh "SELECT count(*) FROM tenancy_get_identity_memberships('$I1')")"
sql main_fresh -q -c "SELECT tenancy_set_tenant_status('$TN', 'provisioned')" >/dev/null
expect "C: offered once provisioned, slug may be null" "t" "$(val main_fresh "SELECT (count(*) = 1 AND bool_and(o_tenant_slug IS NULL) AND bool_and(o_is_tenant_owner)) FROM tenancy_get_identity_memberships('$I1')")"
expect "C: tenancy_list_tenants lists provisioned tenants and filters by status" "1|0" "$(val main_fresh "SELECT (SELECT count(*) FROM tenancy_list_tenants('provisioned')) || '|' || (SELECT count(*) FROM tenancy_list_tenants('provisioning'))")"
expect_error "C: a provisioned tenant is never marked failed" main_fresh "SELECT tenancy_set_tenant_status('$TN', 'provisioning_failed')" "illegal_status_transition"
expect "C: find_membership reports the status" "$M1|active" "$(val main_fresh "SELECT o_id || '|' || o_status FROM tenancy_find_membership('$TN', '$I1')")"
expect_error "C: the last active owner cannot be suspended" main_fresh "SELECT tenancy_set_membership_status('$M1', 'suspended')" "cannot_suspend_tenant_owner"
sql main_fresh -q -c "SELECT tenancy_create_membership('$M3', '$TN', '$I2', true, NULL)" >/dev/null
sql main_fresh -q -c "SELECT tenancy_set_membership_status('$M1', 'suspended')" >/dev/null
expect "C: with another active owner the first can be suspended" "suspended" "$(val main_fresh "SELECT o_status FROM tenancy_find_membership('$TN', '$I1')")"
expect "C: a suspended member is not offered" "0" "$(val main_fresh "SELECT count(*) FROM tenancy_get_identity_memberships('$I1')")"
expect_error "C: an unknown membership status is refused" main_fresh "SELECT tenancy_set_membership_status('$M3', 'deleted')" "invalid_status"
expect "C: the mirror applies NO last-owner rule (M3 is the only active owner)" "1|suspended" "$(val main_fresh "SELECT (SELECT o_updated FROM tenancy_mirror_membership_status('$M3', 'suspended')) || '|' || (SELECT o_status FROM tenancy_find_membership('$TN', '$I2'))")"
expect "C: a mirror for an unknown membership updates nothing" "0" "$(val main_fresh "SELECT o_updated FROM tenancy_mirror_membership_status('99999999-9999-4999-8999-999999999999', 'removed')")"
expect_error "C: the mirror refuses an unknown status" main_fresh "SELECT * FROM tenancy_mirror_membership_status('$M3', 'deleted')" "invalid_status"
sql main_fresh -q -c "SELECT * FROM tenancy_mirror_membership_status('$M3', 'active')" >/dev/null
expect "C: active member identities of a tenant" "$I2" "$(val main_fresh "SELECT string_agg(o_identity_id::text, ',') FROM tenancy_list_active_member_identities('$TN')")"
sql main_fresh -q -c "SELECT tenancy_record_mirror_drift('$M1', 'suspended')" >/dev/null
expect "C: a recorded drift is listed (ids and status only)" "$M1|suspended" "$(val main_fresh "SELECT o_membership_id || '|' || o_auth_status FROM tenancy_list_mirror_drift()")"
expect "C: a cleared drift is gone" "0" "$(sql main_fresh -q -c "SELECT tenancy_clear_mirror_drift('$M1')" >/dev/null; val main_fresh "SELECT count(*) FROM tenancy_list_mirror_drift()")"
expect "C: the ledger keeps the FIRST row and returns the winner" "$TN" "$(sql main_fresh -q -c "SELECT * FROM tenancy_save_request('k1', '$I1', '$TN', '$M1', 'acme_tenant_x')" >/dev/null; val main_fresh "SELECT o_tenant_id FROM tenancy_save_request('k1', '$I1', '99999999-9999-4999-8999-999999999999', '$M2', 'other')")"
P="eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee"
expect "C: platform_upsert registers the platform" "$P" "$(val main_fresh "SELECT o_platform_id FROM platform_upsert('$P', 'Acme', 'acme', 'external')")"
expect "C: platform_upsert is idempotent" "1" "$(val main_fresh "SELECT count(*) FROM platform_upsert('$P', 'Acme', 'acme', 'external')" >/dev/null; val main_fresh "SELECT count(*) FROM platform")"
expect_error "C: a 20-character slug is refused" main_fresh "SELECT * FROM platform_upsert('$P', 'Acme', 'aaaaaaaaaaaaaaaaaaaa', 'external')" "invalid_platform_slug"
expect_error "C: another platform id over a registered one is refused" main_fresh "SELECT * FROM platform_upsert('ffffffff-ffff-4fff-8fff-ffffffffffff', 'Acme', 'acme', 'external')" "platform_id_conflict"
expect_error "C: the platform table refuses a second row" main_fresh "INSERT INTO platform (singleton, platform_name, platform_slug) VALUES (false, 'x', 'x')" "check"
expect "C: the core migration is idempotent on the fresh schema" "1" "$(val main_fresh "SELECT count(*) FROM pg_constraint WHERE conname='tenants_tenant_db_name_key'")"

# ---------------------------------------------------------------- D. main, EXISTING tenants / tenant_memberships (old db_status)
mkdb main_legacy
for f in tenancy_upsert_tenant tenancy_get_identity_memberships; do load main_legacy "$MF/$f.pgsql.template"; done
sql main_legacy -q <<'SQL' >/dev/null
CREATE TABLE tenants (uuid UUID PRIMARY KEY, name TEXT, db_status TEXT, tenant_db_name TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), address TEXT);
INSERT INTO tenants (uuid, name, db_status, tenant_db_name) VALUES
 ('00000000-0000-4000-8000-000000000001', 'a', 'active',    'p_a'),
 ('00000000-0000-4000-8000-000000000002', 'b', 'creating',  'p_b'),
 ('00000000-0000-4000-8000-000000000003', 'c', 'error',     'p_c'),
 ('00000000-0000-4000-8000-000000000004', 'd', 'suspended', 'p_d'),
 ('00000000-0000-4000-8000-000000000005', 'e', 'deleted',   'p_e'),
 ('00000000-0000-4000-8000-000000000006', 'f', 'weird',     'p_f');
CREATE TABLE tenant_memberships (id UUID PRIMARY KEY, identity_id UUID NOT NULL, tenant_id UUID NOT NULL, role TEXT);
INSERT INTO tenant_memberships VALUES
 ('10000000-0000-4000-8000-000000000001', '11111111-1111-4111-8111-111111111111', '00000000-0000-4000-8000-000000000001', 'owner'),
 ('10000000-0000-4000-8000-000000000002', '22222222-2222-4222-8222-222222222222', '00000000-0000-4000-8000-000000000001', 'cashier');
SQL
CORE="$T/main/migrations/tenancy_core.pgsql.template"
load main_legacy "$CORE"
st() { val main_legacy "SELECT tenant_provisioning_status FROM tenants WHERE uuid='00000000-0000-4000-8000-00000000000$1'"; }
expect "D: active -> provisioned" "provisioned" "$(st 1)"
expect "D: creating -> provisioning" "provisioning" "$(st 2)"
expect "D: error -> provisioning_failed" "provisioning_failed" "$(st 3)"
expect "D: suspended -> provisioned (a suspension is not a provisioning state)" "provisioned" "$(st 4)"
expect "D: deleted -> deprovisioned" "deprovisioned" "$(st 5)"
expect "D: an unmapped db_status is NOT guessed (NULL)" "" "$(st 6)"
sql main_legacy -q -c "INSERT INTO tenants (uuid, name, db_status, tenant_db_name) VALUES ('00000000-0000-4000-8000-000000000007', 'g', 'active', 'p_g')" >/dev/null
load main_legacy "$CORE"
expect "D: a row written since the last run is derived on the next run" "provisioned" "$(st 7)"
expect "D: nothing already derived is changed by a re-run" "provisioned|provisioning|provisioning_failed" "$(st 1)|$(st 2)|$(st 3)"
expect "D: owner flag carried over from the old role column" "true|false" "$(val main_legacy "SELECT (SELECT is_tenant_owner FROM tenant_memberships WHERE id='10000000-0000-4000-8000-000000000001') || '|' || (SELECT is_tenant_owner FROM tenant_memberships WHERE id='10000000-0000-4000-8000-000000000002')")"
expect "D: the old role column is kept" "owner" "$(val main_legacy "SELECT role FROM tenant_memberships WHERE id='10000000-0000-4000-8000-000000000001'")"
expect "D: the tenant FK exists (not validated)" "f" "$(val main_legacy "SELECT convalidated FROM pg_constraint WHERE conname='tenant_memberships_tenant_fk'")"
expect "D: business columns are untouched" "address" "$(val main_legacy "SELECT column_name FROM information_schema.columns WHERE table_name='tenants' AND column_name='address'")"
expect "D: the membership list works on the old tables" "1" "$(val main_legacy "SELECT count(*) FROM tenancy_get_identity_memberships('11111111-1111-4111-8111-111111111111')")"

echo
if [ "$FAILS" -eq 0 ]; then echo "ALL TENANCY SQL CHECKS PASSED"; exit 0; fi
echo "$FAILS CHECK(S) FAILED"; exit 1
