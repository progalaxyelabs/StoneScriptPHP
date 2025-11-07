---
name: Database Drift Detection Feature
about: Implement schema verification to detect database drift
title: 'Implement Database Drift Detection (migrate verify command)'
labels: enhancement, priority-high
assignees: ''
---

## Feature: Database Schema Verification & Drift Detection

### Priority: HIGH (First Priority)

### Overview
Implement a `php migrate.php verify` command that compares the actual database schema against the source of truth files in `src/App/Database/postgresql/` and reports any differences (drift).

### Purpose
Detect when the database schema doesn't match the code repository, which can happen when:
- Someone manually modifies the database without creating a migration
- Migrations were applied in some environments but not others
- Database was modified outside the migration system
- Debugging/testing changes were left in place

### Expected Behavior

```bash
php migrate.php verify
```

**Output Example:**
```
Verifying database schema against source files...

❌ Table 'users' in DB is missing column 'status' (present in tables/users.pssql)
❌ Table 'sessions' exists in DB but not in tables/
❌ Function 'get_user_stats' exists in DB but not in functions/
✅ Table 'users' columns match
✅ Function 'authenticate_user' matches
⚠️  Function 'get_orders' signature differs from functions/get_orders.pssql

Summary:
  ✅ 15 objects match
  ❌ 3 objects missing in DB
  ❌ 2 objects missing in code
  ⚠️  1 object has differences

Status: DRIFT DETECTED
```

### Implementation Requirements

#### 1. Filesystem Scanner
- Parse all `.pssql` files in `tables/` folder
- Parse all `.pssql` files in `functions/` folder
- Extract table names, column definitions, data types, constraints
- Extract function names, parameters, return types

#### 2. Database Introspection
Query PostgreSQL system catalogs:
- **Tables**: `information_schema.tables`
- **Columns**: `information_schema.columns`
- **Functions**: `information_schema.routines`
- **Constraints**: `information_schema.table_constraints`
- **Indexes**: `pg_indexes`

#### 3. Comparison Logic
For each object type, detect:
- Objects in code but not in DB (needs migration)
- Objects in DB but not in code (manual change or missing source file)
- Objects that exist in both but have differences (column types, function signatures, etc.)

#### 4. Report Generation
- Clear visual indicators (✅ ❌ ⚠️)
- Detailed diff information
- Exit code: 0 if no drift, 1 if drift detected (CI/CD friendly)

### Technical Details

**File Location**: `Framework/Migrations.php`

**CLI Tool**: `Framework/cli/migrate.php`

**Key Method**:
```php
public function verify(): array {
    $codeDefinitions = $this->getCodeDefinitions();
    $dbDefinitions = $this->getDatabaseDefinitions();
    return $this->diff($codeDefinitions, $dbDefinitions);
}
```

### Success Criteria
- [ ] Can detect tables missing in DB
- [ ] Can detect tables missing in code
- [ ] Can detect column differences (name, type, nullable, default)
- [ ] Can detect functions missing in DB
- [ ] Can detect functions missing in code
- [ ] Can detect function signature changes
- [ ] Returns proper exit codes for CI/CD integration
- [ ] Outputs human-readable report
- [ ] No false positives on fresh installations

### Future Enhancements (Not in Scope)
- Auto-fix functionality (comes later with migration generation)
- Data migration detection
- View and materialized view support
- Trigger detection

### Context
This is part of the larger migration system initiative. This verification feature is the **foundation** that will later support auto-generating migrations from detected differences.

Related: Migration system will include `up`, `down`, `status`, `generate` commands - but verification comes first.
