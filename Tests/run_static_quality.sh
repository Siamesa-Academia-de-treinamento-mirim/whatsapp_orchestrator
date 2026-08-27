#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."
php Tests/run_unit.php
php Tests/run_product_static.php
find . -name '*.php' -type f -exec php -l {} + >/dev/null
node --check Assets/js/chatwoot.js
node --check Assets/js/hub-workspace.js
git diff --check
echo "Static quality gate passed."
