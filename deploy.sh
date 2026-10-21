#!/bin/bash
# One-command deploy over HTTPS to public_html/deploy.php (see deploy/deploy.php).
#   bash deploy.sh <zip> <url>        e.g. bash deploy.sh vega-erp-phase6-upgrade.zip https://vegatest1.distrosku.com
#   bash deploy.sh status <url>
#   bash deploy.sh rollback <url>
set -e
cd "$(dirname "$0")"
if [ "$1" = status ] || [ "$1" = rollback ]; then exec php deploy/deploy-client.php "$1" "$2"; fi
exec php deploy/deploy-client.php deploy "$1" "$2"
