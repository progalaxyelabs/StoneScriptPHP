#!/bin/bash
cd /tmp/test-project

# Verify OAuth files exist as documented
[ -f "Framework/Oauth/Google.php" ] || exit 1
[ -f "Framework/google-callback.php" ] || exit 1

echo "✓ OAuth files present"
