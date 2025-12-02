#!/bin/bash
cd /tmp
composer create-project progalaxyelabs/stonescriptphp test-project --no-interaction
cd test-project
[ -f "stone" ] || exit 1
[ -f "composer.json" ] || exit 1
[ -d "Framework" ] || exit 1
echo "✓ Installation successful"
