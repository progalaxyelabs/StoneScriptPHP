#!/bin/bash
cd /tmp/test-project

# Generate route as documented
php stone generate route update-trophies

# Verify file created
[ -f "src/App/Routes/UpdateTrophiesRoute.php" ] || exit 1

# Verify class exists
grep "class UpdateTrophiesRoute" src/App/Routes/UpdateTrophiesRoute.php || exit 1

# Verify implements IRouteHandler
grep "IRouteHandler" src/App/Routes/UpdateTrophiesRoute.php || exit 1

echo "✓ Route generation matches documentation"
