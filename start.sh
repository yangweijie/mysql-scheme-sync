#!/bin/bash
cd "$(dirname "$0")"

if command -v php85 &>/dev/null; then
    exec php85 bin/mysql-schema-sync.php "$@"
elif command -v php &>/dev/null; then
    exec php bin/mysql-schema-sync.php "$@"
else
    echo ""
    echo "[ERROR] PHP not found. Please install PHP 8.5+ and add to PATH."
    echo "        Or use: php85 bin/mysql-schema-sync.php"
    echo ""
    exit 1
fi
