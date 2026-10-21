#!/bin/bash
# Local end-to-end test of deploy.php with `php -S` (multi-worker so the health check can call back in).
# Uses a throwaway home in /tmp/vdt, SQLite, and the real phase-5 package plus derived test packages.
set -u
cd /workspace/vega-erp
PKG=${1:-vega-erp-phase5-upgrade.zip}
T=/tmp/vdt; H=$T/home; PORT=8099; URL=http://127.0.0.1:$PORT
pass=0; fail=0
ok() { echo "PASS: $1"; pass=$((pass+1)); }
ko() { echo "FAIL: $1"; fail=$((fail+1)); }
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null; sleep 0.5
rm -rf $T && mkdir -p $H && cd $H && unzip -q /workspace/vega-erp/$PKG && rm -f public_html/upgrade-*.php README_UPGRADE.md deploy-manifest.json && cp /workspace/vega-erp/deploy/deploy.php public_html/deploy.php
# simulate a pre-deploy install (phase 5 installed via upgrade page): no manifest in laravel/
rm -f laravel/deploy-manifest.json
mkdir -p laravel/storage/{app/public,framework/{cache/data,sessions,views},logs}
touch $T/db.sqlite
cat > laravel/.env <<ENV
APP_NAME="Vega ERP TEST"
APP_ENV=production
APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));')
APP_DEBUG=false
APP_URL=$URL
DB_CONNECTION=sqlite
DB_DATABASE=$T/db.sqlite
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
DEPLOY_HEALTH_URL=$URL/up,$URL/admin/login
ENV
(cd laravel && php artisan migrate --force -q && php artisan db:seed --force -q) || { echo "install failed"; exit 1; }
printf 'AddHandler application/x-httpd-php82 .php\n%s' "$(cat public_html/.htaccess)" > public_html/.htaccess   # host line to keep
cp /workspace/vega-erp/secrets/deploy_hmac_secret laravel/.deploy-secret && chmod 600 laravel/.deploy-secret  # as the upgrade page does
cat > $T/router.php <<'R'
<?php
$p = __DIR__.'/home/public_html'.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($p)) { return false; }
require __DIR__.'/home/public_html/index.php';
R
(cd $H/public_html && PHP_CLI_SERVER_WORKERS=4 setsid nohup php -d post_max_size=3M -S 127.0.0.1:$PORT $T/router.php >$T/server.log 2>&1 &)
sleep 1.5
cd /workspace/vega-erp
[ "$(curl -s -o /dev/null -w '%{http_code}' $URL/up)" = 200 ] && ok "fresh install healthy" || ko "fresh install /up"

# derived packages
mk() { # name version mutate-cmd
  rm -rf $T/pk && mkdir -p $T/pk && (cd $T/pk && unzip -q /workspace/vega-erp/$PKG && eval "$3" &&
    php -r '$m=json_decode(file_get_contents("deploy-manifest.json"),true); $m["version"]=$argv[1]; file_put_contents("deploy-manifest.json", json_encode($m)); file_put_contents("laravel/deploy-manifest.json", json_encode($m));' "$2" &&
    zip -qr $T/$1.zip laravel public_html deploy-manifest.json)
}
mk v2 test-v2 'echo "<p>v2 asset</p>" > public_html/v2.txt'
mk bad test-bad 'echo "<?php throw new RuntimeException(\"broken build\");" > laravel/config/zzz_broken.php'
mk fatal test-fatal 'echo "<?php this is not php" > laravel/config/zzz_fatal.php'

# 1 unauthenticated / GET / bad HMAC -> bare 404
[ "$(curl -s -o /dev/null -w '%{http_code}' $URL/deploy.php)" = 404 ] && ok "GET -> 404" || ko "GET"
c=$(curl -s -o $T/o -w '%{http_code}' -X POST -H "X-Vega-Timestamp: $(date +%s)" -H "X-Vega-Nonce: $(openssl rand -hex 16)" -H "X-Vega-Signature: $(openssl rand -hex 32)" "$URL/deploy.php?action=status")
[ "$c" = 404 ] && ! grep -qi "signature\|secret" $T/o && ok "bad HMAC -> 404, no info" || ko "bad HMAC ($c)"

# 2 replay + stale timestamp
sign() { php -r '$q=$argv[1]; parse_str($q,$a); ksort($a); $qs=http_build_query($a,"","&",PHP_QUERY_RFC3986); echo hash_hmac("sha256","VEGA-DEPLOY-HMAC-V1\n$argv[2]\n$argv[3]\n$qs\n".hash("sha256",""), trim(file_get_contents("secrets/deploy_hmac_secret")));' "$1" "$2" "$3"; }
TS=$(date +%s); N=$(openssl rand -hex 16); S=$(sign action=status $TS $N)
c1=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Vega-Timestamp: $TS" -H "X-Vega-Nonce: $N" -H "X-Vega-Signature: $S" "$URL/deploy.php?action=status")
c2=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Vega-Timestamp: $TS" -H "X-Vega-Nonce: $N" -H "X-Vega-Signature: $S" "$URL/deploy.php?action=status")
[ "$c1" = 200 ] && [ "$c2" = 404 ] && ok "signed status 200, replay 404" || ko "replay ($c1/$c2)"
TS=$(( $(date +%s) - 400 )); N=$(openssl rand -hex 16); S=$(sign action=status $TS $N)
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "X-Vega-Timestamp: $TS" -H "X-Vega-Nonce: $N" -H "X-Vega-Signature: $S" "$URL/deploy.php?action=status")" = 404 ] && ok "timestamp >5 min -> 404" || ko "stale ts"

# 3 package signed with the wrong Ed25519 key -> refused, nothing changed
mkdir -p $T/badkeys && cp secrets/deploy_hmac_secret $T/badkeys/ && php -r '$k=sodium_crypto_sign_keypair(); file_put_contents($argv[1], base64_encode(sodium_crypto_sign_secretkey($k)));' $T/badkeys/deploy_ed25519_secret
VEGA_SECRETS=$T/badkeys bash deploy.sh $T/v2.zip $URL > $T/o 2>&1; grep -q "package signature invalid" $T/o && [ ! -e $H/public_html/v2.txt ] && ok "wrong Ed25519 key refused" || { ko "bad sig"; cat $T/o; }

# 4 deploy v2 in 1 MB chunks (server post_max_size=3M forces chunking)
VEGA_CHUNK=1048576 bash deploy.sh $T/v2.zip $URL > $T/o 2>&1
grep -q '"ok": true' $T/o && grep -q '"version": "test-v2"' $T/o && [ -e $H/public_html/v2.txt ] && [ -d $H/laravel-prev ] && ok "chunked deploy v2 + laravel-prev kept" || { ko "deploy v2"; cat $T/o; }
grep -q "uploaded .* / " $T/o && [ "$(grep -c 'uploaded' $T/o)" -gt 5 ] && ok "upload was chunked ($(grep -c uploaded $T/o) chunks)" || ko "chunks"
[ -f $H/laravel/.env ] && [ -f $H/laravel/.deploy-secret ] && [ -d $H/laravel/storage/framework ] && [ ! -e $H/laravel-prev/storage ] && ok ".env/.deploy-secret/storage carried over" || ko "carry"
head -1 $H/public_html/.htaccess | grep -q AddHandler && grep -q "deploy\\\\.php" $H/public_html/.htaccess && ok ".htaccess host line kept + deploy.php allowed" || ko "htaccess merge"
[ -f $H/public_html/deploy.php ] && [ "$(curl -s -o /dev/null -w '%{http_code}' $URL/up)" = 200 ] && ok "site healthy after deploy" || ko "health after deploy"
[ "$(stat -c %a $H/laravel/.deploy-secret)" = 600 ] && ok ".deploy-secret mode 600" || ko "secret mode"

# 5 status + manual rollback -> previous version
bash deploy.sh status $URL > $T/o 2>&1; grep -q '"version": "test-v2"' $T/o && ok "status shows test-v2" || { ko status; cat $T/o; }
bash deploy.sh rollback $URL > $T/o 2>&1; grep -q '"ok": true' $T/o && [ ! -e $H/public_html/v2.txt ] && [ "$(curl -s -o /dev/null -w '%{http_code}' $URL/up)" = 200 ] && ok "manual rollback (assets restored, healthy)" || { ko rollback; cat $T/o; }
bash deploy.sh status $URL > $T/o 2>&1; grep -q 'unknown (pre-deploy package)' $T/o && ok "status after rollback = original" || { ko "status after rollback"; cat $T/o; }

# 6 broken packages roll back automatically
bash deploy.sh $T/v2.zip $URL > /dev/null 2>&1   # back on v2 so there is a prev
bash deploy.sh $T/bad.zip $URL > $T/o 2>&1
grep -q '"rolled_back": true' $T/o && grep -q "broken build" $T/o && [ "$(curl -s -o /dev/null -w '%{http_code}' $URL/up)" = 200 ] && ok "exception during migrate -> auto rollback, healthy" || { ko "bad pkg"; cat $T/o; }
bash deploy.sh status $URL > $T/o 2>&1; grep -q '"version": "test-v2"' $T/o && ok "still on test-v2 after failed deploy" || { ko "version after bad"; cat $T/o; }
bash deploy.sh $T/v2.zip $URL > /dev/null 2>&1
bash deploy.sh $T/fatal.zip $URL > $T/o 2>&1
grep -q '"rolled_back": true' $T/o && [ "$(curl -s -o /dev/null -w '%{http_code}' $URL/up)" = 200 ] && ok "PHP fatal in new code -> auto rollback, healthy" || { ko "fatal pkg"; cat $T/o; }

# 7 overlapping deploy refused (lock held)
php -r '$h=fopen($argv[1],"c"); flock($h, LOCK_EX); sleep(4);' $H/deploy-work/deploy.lock & sleep 0.5; LOCKPID=$!
bash deploy.sh rollback $URL > $T/o 2>&1; grep -q "another deploy is running" $T/o && ok "lock prevents overlapping deploys" || { ko lock; cat $T/o; }
wait $LOCKPID

# 8 rate limit: 10 bad attempts lock out the IP (even valid requests then get 404)
for i in $(seq 1 10); do curl -s -o /dev/null -X POST "$URL/deploy.php?action=status"; done
bash deploy.sh status $URL > $T/o 2>&1; grep -q "HTTP 404" $T/o && ok "rate limit after repeated bad auth" || { ko "rate"; cat $T/o; }

pkill -f "php -S 127.0.0.1:$PORT"
echo "== $pass passed, $fail failed =="
[ $fail = 0 ]
