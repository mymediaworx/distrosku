#!/bin/bash
# Packages Vega ERP for SiteGround: laravel/ + public_html/ + one-time setup-<token>.php. No .env / secrets.
set -e
cd /workspace/vega-erp
T=$(cat .token)
rm -rf stage vega-erp-phase1.zip && mkdir -p stage/laravel stage/public_html
rsync -a --exclude /public --exclude /vendor --exclude node_modules --exclude /.env --exclude 'database/database.sqlite' --exclude .git --exclude /tests \
  --exclude 'storage/logs/*.log' --exclude 'storage/framework/sessions/*' --exclude 'storage/framework/views/*.php' --exclude 'storage/framework/cache/data/*' \
  --exclude 'bootstrap/cache/config.php' --exclude 'bootstrap/cache/routes-*.php' --exclude 'bootstrap/cache/events.php' \
  --exclude 'bootstrap/cache/packages.php' --exclude 'bootstrap/cache/services.php' --exclude .phpunit.result.cache laravel/ stage/laravel/
(cd stage/laravel && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction -q --no-scripts && php artisan package:discover -q)
rsync -a --exclude /storage --exclude /hot laravel/public/ stage/public_html/
sed -i "s#__DIR__.'/../storage/framework/maintenance.php'#__DIR__.'/../laravel/storage/framework/maintenance.php'#; s#__DIR__.'/../vendor/autoload.php'#__DIR__.'/../laravel/vendor/autoload.php'#; s#__DIR__.'/../bootstrap/app.php'#__DIR__.'/../laravel/bootstrap/app.php'#" stage/public_html/index.php
sed "s/__TOKEN__/$T/g" setup.tpl.php > stage/public_html/setup-$T.php
php -l stage/public_html/setup-$T.php >/dev/null
cp README_DEPLOY.md stage/
rm -f stage/laravel/.env
! grep -rIl --exclude-dir=vendor 'S0cM0nk3y' stage/ || { echo "SECRET FOUND"; exit 1; }
(cd stage && zip -qr ../vega-erp-phase1.zip laravel public_html README_DEPLOY.md)
ls -la vega-erp-phase1.zip
