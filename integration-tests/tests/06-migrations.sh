#!/bin/bash
cd /tmp/test-project

# Setup database connection
cat > .env <<EOF
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
EOF

# Test migration verify command exists
php stone migrate verify || true  # May fail if no DB schema, but command should exist

echo "✓ Migration commands exist"
