#!/bin/bash
cd /tmp/test-project

# Create dummy function file
mkdir -p src/App/Database/postgres/functions
cat > src/App/Database/postgres/functions/get_user.pssql <<'EOF'
CREATE OR REPLACE FUNCTION get_user(user_id INTEGER)
RETURNS TABLE (id INTEGER, name TEXT) AS $$
BEGIN
  RETURN QUERY SELECT id, name FROM users WHERE id = user_id;
END;
$$ LANGUAGE plpgsql;
EOF

# Generate model
php stone generate model get_user.pssql

# Verify model created
[ -f "src/App/Database/Functions/FnGetUser.php" ] || exit 1

echo "✓ Model generation works"
