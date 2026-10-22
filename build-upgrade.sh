#!/bin/bash
# UPGRADE package for an existing install: laravel/ (code + vendor, NO .env, NO storage/) + public_html assets + upgrade-<token>.php.
# Scrub values come from secrets/ (never committed) or the environment; nothing sensitive is written in this script.
CLIENT_ID=${VEGA_CLIENT_ID:-$(tr -d '\r\n' < secrets/client_id 2>/dev/null)}
GHL_LOC=${GHL_LOCATION_ID:-$(sed -n 's/^GHL_LOCATION_ID=//p' secrets/ghl.env 2>/dev/null | tr -d '\r\n')}
set -e
has_val() { [ -n "$1" ] && grep -rqF -- "$1" "${@:2}"; }
cd /workspace/vega-erp
# Usage: bash build-upgrade.sh <phase>   (token in .upgrade_token)
PHASE=${1:?usage: build-upgrade.sh <phase number>}
T=$(cat .upgrade_token)
# SRC = source app tree (default laravel); PUBSRC = its web root; README = upgrade readme. Hotfixes build from a frozen tree.
SRC=${SRC:-laravel}
PUBSRC=${PUBSRC:-$SRC/public}
README=${README:-README_UPGRADE.md}
OUT=vega-erp-phase${PHASE}-upgrade.zip
# Idempotent seeders the upgrade must run, cumulative per phase (an install may skip a phase).
case "$PHASE" in
  2) SEEDERS="TaxRateSeeder" ;;
  3) SEEDERS="TaxRateSeeder Phase3Seeder" ;;
  4) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder" ;;
  4.1) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder" ;;
  5) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder" ;;
  6) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder" ;;
  7) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder" ;;
  7.1) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder" ;;
  7.2) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder" ;;
  7.3) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder" ;;
  8) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder" ;;
  9) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder" ;;
  10) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder" ;;
  11) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder" ;;
  12) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder" ;;
  13) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder" ;;
  14) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder" ;;
  14.1) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  15) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  16) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  17) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  17.1) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  17.2) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  17.3) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  18) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  19|19.1|19.2|19.3|19.4|19.5|19.6|20.0) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  18.2) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  18.1) SEEDERS="TaxRateSeeder Phase3Seeder Phase4Seeder Phase8Seeder Phase11Seeder Phase12Seeder Phase14Seeder" ;;
  *) echo "No seeder list for phase $PHASE"; exit 1 ;;
esac
SEEDERS_PHP=$(for s in $SEEDERS; do printf "'%s', " "$s"; done)
rm -rf stage-up $OUT && mkdir -p stage-up/laravel stage-up/public_html
rsync -a --exclude /public --exclude /vendor --exclude /storage --exclude node_modules --exclude /.env --exclude '/.env.*.local' --exclude /.deploy-secret --exclude /deploy-manifest.json \
  --exclude 'database/database.sqlite' --exclude .git --exclude /tests --exclude .phpunit.result.cache \
  --exclude 'bootstrap/cache/*.php' --exclude /phpunit.xml "$SRC"/ stage-up/laravel/
(cd stage-up/laravel && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction -q --no-scripts)
rm -f stage-up/laravel/bootstrap/cache/*.php
rsync -a --exclude /storage --exclude /hot --exclude 'upgrade-*.php' "$PUBSRC"/ stage-up/public_html/
sed -i "s#__DIR__.'/../storage/framework/maintenance.php'#__DIR__.'/../laravel/storage/framework/maintenance.php'#; s#__DIR__.'/../vendor/autoload.php'#__DIR__.'/../laravel/vendor/autoload.php'#; s#__DIR__.'/../bootstrap/app.php'#__DIR__.'/../laravel/bootstrap/app.php'#" stage-up/public_html/index.php
UP=stage-up/public_html/upgrade-$T.php
sed "s/__TOKEN__/$T/g; s/__PHASE__/$PHASE/g; s/__SEEDERS__/$SEEDERS_PHP/" upgrade.tpl.php > $UP
php -l $UP >/dev/null
# ---- build checks: the generated upgrade script must match this phase ----
! grep -oE '__[A-Z_]+__' $UP | grep -vqE '^__(DIR|FILE|LINE|CLASS|FUNCTION|METHOD|NAMESPACE)__$' || { echo "CHECK FAILED: unreplaced placeholder in $UP"; exit 1; }
grep -q "UPGRADE runner (phase $PHASE)" $UP || { echo "CHECK FAILED: header is not phase $PHASE"; exit 1; }
ACTUAL=$(php -r 'preg_match("/const UPGRADE_SEEDERS = \[(.*)\];/", file_get_contents($argv[1]), $m); preg_match_all("/[\x27\"]([A-Za-z0-9_]+)[\x27\"]/", $m[1] ?? "", $n); echo implode(" ", $n[1]);' $UP)
[ "$ACTUAL" = "$SEEDERS" ] || { echo "CHECK FAILED: upgrade seeders '$ACTUAL' != expected '$SEEDERS'"; exit 1; }
for s in $SEEDERS; do test -f stage-up/laravel/database/seeders/$s.php || { echo "CHECK FAILED: seeder $s.php missing"; exit 1; }; done
grep -q "Phase $PHASE UPGRADE" "$README" || { echo "CHECK FAILED: README_UPGRADE.md is not for phase $PHASE"; exit 1; }
echo "Upgrade script OK: phase $PHASE, seeders: $SEEDERS"
# phase-specific package checks
if [ "$PHASE" = "7" ]; then
  for f in database/migrations/2026_10_27_000001_phase7_msa_multicat.php app/Msa/Layout.php app/Msa/Writer.php app/Msa/MulticatBuilder.php app/Msa/MsaSettings.php \
           app/Models/MsaSubmission.php app/Filament/Pages/MsaWeekly.php app/Filament/Pages/MsaFixList.php resources/views/filament/pages/msa-weekly.blade.php; do
    test -f stage-up/laravel/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.7.0'" stage-up/laravel/config/erp.php || { echo "CHECK FAILED: erp version is not 0.7.0"; exit 1; }
  grep -q "'msa' => \['view' => \[\], 'manage' => \[\]\]" stage-up/laravel/config/erp.php || { echo "CHECK FAILED: msa permission is not admin-level"; exit 1; }
  echo "Phase 7 files OK"
fi
if [ "$PHASE" = "7.1" ]; then
  for f in resources/views/partials/powered-by.blade.php resources/views/partials/powered-by-pdf.blade.php resources/views/partials/powered-by-label.blade.php resources/views/errors/403.blade.php resources/views/errors/404.blade.php resources/views/errors/500.blade.php resources/views/errors/503.blade.php resources/views/vendor/mail/html/footer.blade.php; do test -f stage-up/laravel/$f || { echo "CHECK FAILED: $f missing"; exit 1; }; done
  for f in images/distrosku-logo.png images/distrosku-logo.webp; do test -s stage-up/public_html/$f || { echo "CHECK FAILED: public_html/$f missing"; exit 1; }; done
  grep -q "'version' => '0.7.1'" stage-up/laravel/config/erp.php || { echo "CHECK FAILED: erp version is not 0.7.1"; exit 1; }
  grep -q "partials.powered-by" stage-up/laravel/app/Providers/Filament/AdminPanelProvider.php || { echo "CHECK FAILED: footer hook missing"; exit 1; }
  for f in resources/views/partials/powered-by.blade.php resources/views/partials/powered-by-pdf.blade.php resources/views/partials/powered-by-label.blade.php resources/views/vendor/mail/html/footer.blade.php; do grep -q "Powered by DistroSKU" stage-up/laravel/$f || { echo "CHECK FAILED: $f lacks 'Powered by DistroSKU'"; exit 1; }; done
  for f in $(grep -rl "</body>" stage-up/laravel/resources/views --include=*.blade.php); do grep -qE "powered-by|minimal-ds|mail::footer|footer" $f || { echo "CHECK FAILED: $f has no DistroSKU footer"; exit 1; }; done
  echo "Phase 7.1 files OK (no migrations)"
fi
if [ "$PHASE" = "7.2" ]; then
  grep -q "'version' => '0.7.2'" stage-up/laravel/config/erp.php || { echo "CHECK FAILED: erp version is not 0.7.2"; exit 1; }
  test -f stage-up/laravel/app/Support/PdfBrand.php || { echo "CHECK FAILED: PdfBrand missing"; exit 1; }
  grep -q "dompdf.public_path" stage-up/laravel/app/Providers/AppServiceProvider.php || { echo "CHECK FAILED: dompdf public_path fix missing"; exit 1; }
  grep -q "PdfBrand::logoDataUri" stage-up/laravel/resources/views/partials/powered-by-pdf.blade.php || { echo "CHECK FAILED: pdf footer not using PdfBrand"; exit 1; }
  ! grep -qE "@php\(" stage-up/laravel/resources/views/partials/powered-by-pdf.blade.php || { echo "CHECK FAILED: inline @php() in pdf footer"; exit 1; }
  test -s stage-up/public_html/images/distrosku-logo.png || { echo "CHECK FAILED: logo missing"; exit 1; }
  test ! -e stage-up/laravel/public || { echo "CHECK FAILED: package must not depend on laravel/public"; exit 1; }
  [ "$(ls stage-up/laravel/database/migrations | tail -1)" = "2026_10_27_000001_phase7_msa_multicat.php" ] || { echo "CHECK FAILED: 7.2 must not ship migrations beyond phase 7"; exit 1; }
  ! grep -rqiE "phase ?8|Phase8" stage-up/laravel/app stage-up/laravel/database stage-up/laravel/config || { echo "CHECK FAILED: phase-8 code leaked into 7.2"; exit 1; }
  # live-layout smoke: laravel/ + sibling public_html/, no laravel/public, production mode; GD on and forced off
  mkdir -p stage-up/laravel/storage/framework/views stage-up/laravel/storage/framework/cache stage-up/laravel/storage/logs
  OUTP=$(cd stage-up/laravel && APP_KEY=base64:$(head -c32 /dev/urandom | base64) APP_ENV=production APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array php -r '
    require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach ([null, false] as $g) { App\Support\PdfBrand::$forceGd = $g;
      $pdf = Barryvdh\DomPDF\Facade\Pdf::loadHTML("<html><body>x".view("partials.powered-by-pdf")->render().view("partials.powered-by-label")->render()."</body></html>")->output();
      echo str_starts_with($pdf, "%PDF") ? "ok " : "bad "; }' 2>&1)
  rm -rf stage-up/laravel/storage
  [ "$OUTP" = "ok ok " ] || { echo "CHECK FAILED: live-layout PDF smoke: $OUTP"; exit 1; }
  echo "Phase 7.2 files OK, live-layout PDF smoke OK (GD on/off)"
fi
if [ "$PHASE" = "7.3" ]; then
  L=stage-up/laravel
  grep -q "'version' => '0.7.3'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.7.3"; exit 1; }
  grep -q "dompdf.public_path" $L/app/Providers/AppServiceProvider.php || { echo "CHECK FAILED: 7.2 dompdf public_path fix missing"; exit 1; }
  grep -qF "@media print { .distrosku-powered { display:none !important } }" $L/resources/views/partials/powered-by.blade.php || { echo "CHECK FAILED: print CSS missing"; exit 1; }
  for v in docs/invoice docs/statement catalog/catalog-pdf catalog/labels-pdf catalog/labels reports/pdf reports/management-pdf; do
    ! grep -qiE "powered-by|Powered by DistroSKU" $L/resources/views/$v.blade.php || { echo "CHECK FAILED: footer still on printable $v"; exit 1; }
  done
  test ! -e $L/resources/views/partials/powered-by-pdf.blade.php && test ! -e $L/resources/views/partials/powered-by-label.blade.php || { echo "CHECK FAILED: printable footer partials still shipped"; exit 1; }
  for f in $L/resources/views/errors/404.blade.php $L/resources/views/vendor/mail/html/footer.blade.php; do test -f $f || { echo "CHECK FAILED: $f missing"; exit 1; }; done
  test ! -e $L/public || { echo "CHECK FAILED: package must not depend on laravel/public"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_10_27_000001_phase7_msa_multicat.php" ] || { echo "CHECK FAILED: 7.3 must not ship migrations beyond phase 7"; exit 1; }
  ! grep -rqiE "phase ?8|Phase8" $L/app $L/database $L/config || { echo "CHECK FAILED: phase-8 code leaked into 7.3"; exit 1; }
  # live-layout smoke: laravel/ + sibling public_html/, no laravel/public, production mode, real PDF views compiled
  mkdir -p $L/storage/framework/views $L/storage/framework/cache $L/storage/logs
  OUTP=$(cd $L && APP_KEY=base64:$(head -c32 /dev/urandom | base64) APP_ENV=production APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array php -r '
    require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $pdf = Barryvdh\DomPDF\Facade\Pdf::loadView("catalog.catalog-pdf", ["rows" => [], "company" => (object) ["name" => "Smoke"]])->output();
    echo str_starts_with($pdf, "%PDF") ? "ok" : "bad"; file_put_contents("/tmp/smoke73.pdf", $pdf);' 2>&1)
  rm -rf $L/storage
  [ "$OUTP" = "ok" ] || { echo "CHECK FAILED: live-layout PDF smoke: $OUTP"; exit 1; }
  ! pdftotext /tmp/smoke73.pdf - 2>/dev/null | grep -q "Powered by DistroSKU" || { echo "CHECK FAILED: smoke PDF contains footer"; exit 1; }
  echo "Phase 7.3 files OK, live-layout PDF smoke OK (no footer in PDF)"
fi
if [ "$PHASE" = "8" ]; then
  for f in database/migrations/2026_10_28_000001_phase8_accounting.php database/seeders/Phase8Seeder.php app/Accounting/ApService.php app/Accounting/BankReconService.php \
           app/Accounting/FinancialStatements.php app/Accounting/Ledger.php app/Accounting/PeriodService.php app/Accounting/DunningService.php \
           app/Filament/Resources/VendorBillResource.php app/Filament/Resources/VendorPaymentResource.php app/Filament/Resources/BankAccountResource.php \
           app/Filament/Pages/BankReconciliation.php app/Filament/Pages/PeriodClose.php app/Filament/Pages/Dunning.php \
           app/Filament/Pages/Reports/ApAging.php app/Filament/Pages/Reports/TrialBalance.php app/Filament/Pages/Reports/ProfitLoss.php \
           app/Filament/Pages/Reports/BalanceSheet.php app/Filament/Pages/Reports/CashFlow.php resources/views/reports/dunning-statements.blade.php; do
    test -f stage-up/laravel/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.8.0'" stage-up/laravel/config/erp.php || { echo "CHECK FAILED: erp version is not 0.8.0"; exit 1; }
  for m in ap bank financials periods dunning; do grep -q "'$m' => \['view' => \[\], 'manage' => \[\]\]" stage-up/laravel/config/erp.php || { echo "CHECK FAILED: $m permission is not admin-level"; exit 1; }; done
  [ "$(ls stage-up/laravel/database/migrations | tail -1)" = "2026_10_28_000001_phase8_accounting.php" ] || { echo "CHECK FAILED: last migration is not phase 8"; exit 1; }
  grep -q "PeriodService::assertOpen" stage-up/laravel/app/Services/PostingService.php || { echo "CHECK FAILED: period lock not enforced in PostingService"; exit 1; }
  grep -q "self::GRNI, 0, \$amount" stage-up/laravel/app/Services/PostingService.php || { echo "CHECK FAILED: receipts do not credit 2050"; exit 1; }
  test ! -e stage-up/laravel/resources/views/partials/powered-by-pdf.blade.php || { echo "CHECK FAILED: 7.3 removed printable footers; partial is back"; exit 1; }
  grep -qF "@media print" stage-up/laravel/resources/views/partials/powered-by.blade.php || { echo "CHECK FAILED: 7.3 print CSS missing"; exit 1; }
  grep -q "dompdf.public_path" stage-up/laravel/app/Providers/AppServiceProvider.php || { echo "CHECK FAILED: 7.2 dompdf public_path fix missing"; exit 1; }
  ! grep -rqE "Mail::(to|send|raw|queue)|->send\(new" stage-up/laravel/app/Accounting stage-up/laravel/app/Filament/Pages/Dunning.php || { echo "CHECK FAILED: dunning must never send mail"; exit 1; }
  ! grep -q "powered-by" stage-up/laravel/resources/views/reports/dunning-statements.blade.php || { echo "CHECK FAILED: no footer on phase 8 printables"; exit 1; }
  echo "Phase 8 files OK"
fi
if [ "$PHASE" = "9" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_10_28_000001_phase8_accounting.php database/migrations/2026_10_29_000001_phase9_warehouse_scanning.php \
           app/Warehouse/WarehouseService.php app/Warehouse/ProductLookup.php app/Http/Controllers/WarehousePrintController.php app/Filament/Resources/BinResource.php \
           app/Livewire/Warehouse/Hub.php app/Livewire/Warehouse/Receive.php app/Livewire/Warehouse/Putaway.php app/Livewire/Warehouse/Pick.php app/Livewire/Warehouse/Pack.php \
           resources/views/layouts/warehouse.blade.php resources/views/warehouse/pick-list.blade.php resources/views/warehouse/packing-list.blade.php app/Accounting/ApService.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.9.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.9.0"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_10_29_000001_phase9_warehouse_scanning.php" ] || { echo "CHECK FAILED: last migration is not phase 9"; exit 1; }
  grep -q "'warehouse_scan' => \['view' => \['warehouse'\], 'manage' => \['warehouse'\]\]" $L/config/erp.php || { echo "CHECK FAILED: warehouse_scan permission"; exit 1; }
  grep -q "PeriodService::assertOpen" $L/app/Services/PostingService.php || { echo "CHECK FAILED: phase 8 period lock missing"; exit 1; }
  grep -q "dompdf.public_path" $L/app/Providers/AppServiceProvider.php || { echo "CHECK FAILED: 7.2 dompdf fix missing"; exit 1; }
  test ! -e $L/resources/views/partials/powered-by-pdf.blade.php || { echo "CHECK FAILED: printable footer partial is back"; exit 1; }
  for v in warehouse/pick-list warehouse/packing-list warehouse/print-style reports/dunning-statements; do
    ! grep -qiE "powered-by|Powered by DistroSKU" $L/resources/views/$v.blade.php || { echo "CHECK FAILED: footer on printable $v"; exit 1; }
  done
  grep -q "partials.powered-by" $L/resources/views/layouts/warehouse.blade.php || { echo "CHECK FAILED: warehouse screens need the on-screen footer"; exit 1; }
  grep -q "WarehousePrintController::class, .pick." $L/routes/web.php || { echo "CHECK FAILED: warehouse routes missing"; exit 1; }
  echo "Phase 9 files OK"
fi
if [ "$PHASE" = "10" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_10_29_000001_phase9_warehouse_scanning.php database/migrations/2026_10_30_000001_phase10_lots_uom.php \
           app/Warehouse/LotService.php app/Warehouse/Uom.php app/Warehouse/ProductLookup.php app/Models/Lot.php app/Models/LotStock.php app/Models/LotMovement.php \
           app/Models/PickLineLot.php app/Models/ProductUnit.php app/Models/ProductBarcode.php app/Models/WarehouseEvent.php app/Filament/Resources/LotResource.php \
           app/Filament/Resources/LotResource/Pages/RecallLookup.php app/Filament/Resources/WarehouseEventResource.php resources/views/warehouse/bin-labels.blade.php \
           resources/views/filament/pages/recall-lookup.blade.php app/Accounting/ApService.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.10.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.10.0"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_10_30_000001_phase10_lots_uom.php" ] || { echo "CHECK FAILED: last migration is not phase 10"; exit 1; }
  grep -q "'warehouse_events' => \['view' => \[\], 'manage' => \[\]\]" $L/config/erp.php || { echo "CHECK FAILED: warehouse_events must be admin-level"; exit 1; }
  grep -q "isAdmin()" $L/app/Warehouse/WarehouseService.php || { echo "CHECK FAILED: short pick needs admin approval"; exit 1; }
  grep -q "'over_receipt'" $L/app/Services/PurchaseOrderService.php || { echo "CHECK FAILED: over-receipts not logged"; exit 1; }
  grep -q "consumeForOrder" $L/app/Services/SalesOrderService.php || { echo "CHECK FAILED: delivery does not consume lots"; exit 1; }
  grep -q "PeriodService::assertOpen" $L/app/Services/PostingService.php || { echo "CHECK FAILED: phase 8 period lock missing"; exit 1; }
  grep -q "dompdf.public_path" $L/app/Providers/AppServiceProvider.php || { echo "CHECK FAILED: 7.2 dompdf fix missing"; exit 1; }
  test ! -e $L/resources/views/partials/powered-by-pdf.blade.php || { echo "CHECK FAILED: printable footer partial is back"; exit 1; }
  for v in warehouse/pick-list warehouse/packing-list warehouse/print-style warehouse/bin-labels reports/dunning-statements; do
    ! grep -qiE "powered-by|Powered by DistroSKU" $L/resources/views/$v.blade.php || { echo "CHECK FAILED: footer on printable $v"; exit 1; }
  done
  grep -q "binLabels" $L/routes/web.php || { echo "CHECK FAILED: bin label route missing"; exit 1; }
  echo "Phase 10 files OK"
fi
if [ "$PHASE" = "11" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_10_30_000001_phase10_lots_uom.php database/migrations/2026_10_31_000001_phase11_counts_reorder.php \
           app/Inventory/CountService.php app/Inventory/ReorderService.php app/Models/InventoryCount.php app/Models/InventoryCountLine.php app/Models/InventoryCountScan.php \
           app/Filament/Resources/InventoryCountResource.php app/Filament/Pages/ReorderSuggestions.php app/Livewire/Warehouse/Count.php \
           resources/views/warehouse/count-sheet.blade.php resources/views/livewire/warehouse/count.blade.php app/Warehouse/LotService.php database/seeders/Phase11Seeder.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.11.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.11.0"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_10_31_000001_phase11_counts_reorder.php" ] || { echo "CHECK FAILED: last migration is not phase 11"; exit 1; }
  grep -q "CountService::freezingCount" $L/app/Services/InventoryService.php || { echo "CHECK FAILED: count freeze not enforced"; exit 1; }
  grep -q "countVariance" $L/app/Services/PostingService.php || { echo "CHECK FAILED: count variance GL posting missing"; exit 1; }
  grep -q "'5200'" $L/database/seeders/Phase11Seeder.php || { echo "CHECK FAILED: 5200 account seeder"; exit 1; }
  grep -q "Phase11Seeder" $L/database/seeders/CoreSeeder.php || { echo "CHECK FAILED: CoreSeeder does not call Phase11Seeder"; exit 1; }
  grep -q "PeriodService::assertOpen" $L/app/Services/PostingService.php || { echo "CHECK FAILED: period lock missing"; exit 1; }
  grep -q "dompdf.public_path" $L/app/Providers/AppServiceProvider.php || { echo "CHECK FAILED: 7.2 dompdf fix missing"; exit 1; }
  test ! -e $L/resources/views/partials/powered-by-pdf.blade.php || { echo "CHECK FAILED: printable footer partial is back"; exit 1; }
  for v in warehouse/pick-list warehouse/packing-list warehouse/print-style warehouse/bin-labels warehouse/count-sheet reports/dunning-statements; do
    ! grep -qiE "powered-by|Powered by DistroSKU" $L/resources/views/$v.blade.php || { echo "CHECK FAILED: footer on printable $v"; exit 1; }
  done
  ! grep -rqE "Mail::|->send\(" $L/app/Inventory || { echo "CHECK FAILED: reorder must not send POs"; exit 1; }
  echo "Phase 11 files OK"
fi
if [ "$PHASE" = "13" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_11_02_000001_phase13_return_approval.php app/Tax/ReturnLock.php app/Tax/ReturnService.php \
           app/Filament/Pages/ReturnDrill.php resources/views/filament/pages/return-drill.blade.php app/Inventory/CountService.php app/Inventory/ReorderService.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.13.1'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.13.1"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_02_000001_phase13_return_approval.php" ] || { echo "CHECK FAILED: last migration is not phase 13"; exit 1; }
  grep -q "ReturnLock::register" $L/app/Providers/AppServiceProvider.php || { echo "CHECK FAILED: return lock guards not registered"; exit 1; }
  grep -q "Download blocked" $L/app/Filament/Pages/BaseTaxReportPage.php || { echo "CHECK FAILED: XML download not blocked on schema errors"; exit 1; }
  # XSDs: cigarette must be the docs/xsd-cig set; tobacco must be docs/xsd plus only the documented local patch lines
  if [ -d docs/xsd-cig/KYCigarette2017V1.4 ]; then
    diff -r -x Documents docs/xsd-cig/KYCigarette2017V1.4 $L/resources/ky-edi/KYCigarette2017V1.4 >/dev/null || { echo "CHECK FAILED: cigarette XSD differs from docs/xsd-cig"; exit 1; }
  fi
  if [ -d docs/xsd/KYTobacco2017V1.4 ]; then
    diff -r --strip-trailing-cr -x Documents docs/xsd/KYTobacco2017V1.4 $L/resources/ky-edi/KYTobacco2017V1.5-local | grep -E '^[<>]' \
      | cmp -s - $L/resources/ky-edi/KYTobacco2017V1.5-local/Documents/LOCAL-PATCH.lines || { echo "CHECK FAILED: tobacco XSD differs from docs/xsd beyond the documented patch"; exit 1; }
  fi
  echo "Phase 13 checks OK"
fi
if [ "$PHASE" = "20.0" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_11_12_000001_phase191_marketing_inbound.php database/migrations/2026_11_11_000001_phase19_overrides_ghl.php \
           database/migrations/2026_11_13_000001_phase192_marketing_oneway.php app/Marketing/MarketingFields.php database/migrations/2026_11_14_000001_phase193_ship_to_default.php app/Filament/Resources/CustomerResource/RelationManagers/ShipTosRelationManager.php database/migrations/2026_11_14_000002_phase193_pos_held_sales.php app/Models/PosHeldSale.php database/migrations/2026_11_15_000001_phase194_counter_pickup_default.php app/Pricing/PricingEngine.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.20.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.20.0"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_16_000001_phase196_fulfillment_on_sale.php" ] || { echo "CHECK FAILED: last migration is not 19.6 (0.20.0 adds none)"; exit 1; }
  LONG=$(grep -oE "'[a-z_]+_(fk|idx|uq|index|unique)'" $L/database/migrations/2026_11_16_000001_phase196_fulfillment_on_sale.php | tr -d "'" | awk 'length > 64')
  [ -z "$LONG" ] || { echo "CHECK FAILED: identifier over 64 chars: $LONG"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => false" $L/database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php || { echo "CHECK FAILED: KY registry rule must seed disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  ! grep -rqi "highlevel\|gohighlevel" $L/resources/views || { echo "CHECK FAILED: vendor name in UI"; exit 1; }
  if [ -f secrets/ghl.env ]; then
    PIT=$(sed -n 's/^GHL_PIT=//p' secrets/ghl.env | tr -d '\r\n')
    [ -n "$PIT" ] && grep -rqF "$PIT" stage-up && { echo "CHECK FAILED: marketing token found in package"; exit 1; }
  fi
  for f in app/Marketing/MarketingInbound.php app/Http/Controllers/MarketingWebhookController.php app/Filament/Pages/MarketingInboundPage.php; do
    test ! -f $L/$f || { echo "CHECK FAILED: inbound sync file $f still shipped"; exit 1; }
  done
  ! grep -q "hooks/marketing\|marketing:pull" $L/routes/web.php $L/routes/console.php || { echo "CHECK FAILED: inbound route/schedule still present"; exit 1; }
  ! has_val "$GHL_LOC" $L/app $L/config $L/database $L/resources $L/routes || { echo "CHECK FAILED: live location id in package"; exit 1; }
  grep -q "resolveShipToId" $L/app/Livewire/CounterPos.php $L/app/Livewire/Driver/VanSale.php || { echo "CHECK FAILED: ship-to fix missing"; exit 1; }
  grep -q "fulfillment" $L/app/Livewire/CounterPos.php $L/app/Livewire/Driver/VanSale.php $L/app/Portal/PortalService.php || { echo "CHECK FAILED: fulfillment choice missing"; exit 1; }
  grep -q "where('fulfillment', 'delivery')" $L/app/Delivery/RoutePlanner.php || { echo "CHECK FAILED: routes not delivery-only"; exit 1; }
  for f in app/Filament/Support/OrderActions.php app/Filament/Pages/Receivables.php tests/Feature/Phase200OrderToCashTest.php; do
    test -f $L/$f || [ "$f" = tests/Feature/Phase200OrderToCashTest.php ] || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "return \$this->invoice(\$so);" $L/app/Services/SalesOrderService.php || { echo "CHECK FAILED: deliver does not auto-invoice"; exit 1; }
  grep -q "createAndConfirm" $L/app/Filament/Resources/SalesOrderResource/Pages/CreateSalesOrder.php || { echo "CHECK FAILED: Save & confirm missing"; exit 1; }
  grep -q "settle(" $L/app/Filament/Resources/PaymentResource/Pages/CreatePayment.php || { echo "CHECK FAILED: Payments New not routed through the service"; exit 1; }
  echo "Phase 20.0 checks OK"
fi
if [ "$PHASE" = "19.6" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_11_12_000001_phase191_marketing_inbound.php database/migrations/2026_11_11_000001_phase19_overrides_ghl.php \
           database/migrations/2026_11_13_000001_phase192_marketing_oneway.php app/Marketing/MarketingFields.php database/migrations/2026_11_14_000001_phase193_ship_to_default.php app/Filament/Resources/CustomerResource/RelationManagers/ShipTosRelationManager.php database/migrations/2026_11_14_000002_phase193_pos_held_sales.php app/Models/PosHeldSale.php database/migrations/2026_11_15_000001_phase194_counter_pickup_default.php app/Pricing/PricingEngine.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.19.6'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.19.6"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_16_000001_phase196_fulfillment_on_sale.php" ] || { echo "CHECK FAILED: last migration is not 19.6"; exit 1; }
  LONG=$(grep -oE "'[a-z_]+_(fk|idx|uq|index|unique)'" $L/database/migrations/2026_11_16_000001_phase196_fulfillment_on_sale.php | tr -d "'" | awk 'length > 64')
  [ -z "$LONG" ] || { echo "CHECK FAILED: identifier over 64 chars: $LONG"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => false" $L/database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php || { echo "CHECK FAILED: KY registry rule must seed disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  ! grep -rqi "highlevel\|gohighlevel" $L/resources/views || { echo "CHECK FAILED: vendor name in UI"; exit 1; }
  if [ -f secrets/ghl.env ]; then
    PIT=$(sed -n 's/^GHL_PIT=//p' secrets/ghl.env | tr -d '\r\n')
    [ -n "$PIT" ] && grep -rqF "$PIT" stage-up && { echo "CHECK FAILED: marketing token found in package"; exit 1; }
  fi
  for f in app/Marketing/MarketingInbound.php app/Http/Controllers/MarketingWebhookController.php app/Filament/Pages/MarketingInboundPage.php; do
    test ! -f $L/$f || { echo "CHECK FAILED: inbound sync file $f still shipped"; exit 1; }
  done
  ! grep -q "hooks/marketing\|marketing:pull" $L/routes/web.php $L/routes/console.php || { echo "CHECK FAILED: inbound route/schedule still present"; exit 1; }
  ! has_val "$GHL_LOC" $L/app $L/config $L/database $L/resources $L/routes || { echo "CHECK FAILED: live location id in package"; exit 1; }
  grep -q "resolveShipToId" $L/app/Livewire/CounterPos.php $L/app/Livewire/Driver/VanSale.php || { echo "CHECK FAILED: ship-to fix missing"; exit 1; }
  grep -q "fulfillment" $L/app/Livewire/CounterPos.php $L/app/Livewire/Driver/VanSale.php $L/app/Portal/PortalService.php || { echo "CHECK FAILED: fulfillment choice missing"; exit 1; }
  grep -q "where('fulfillment', 'delivery')" $L/app/Delivery/RoutePlanner.php || { echo "CHECK FAILED: routes not delivery-only"; exit 1; }
  echo "Phase 19.6 checks OK"
fi
if [ "$PHASE" = "19.5" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_11_12_000001_phase191_marketing_inbound.php database/migrations/2026_11_11_000001_phase19_overrides_ghl.php \
           database/migrations/2026_11_13_000001_phase192_marketing_oneway.php app/Marketing/MarketingFields.php database/migrations/2026_11_14_000001_phase193_ship_to_default.php app/Filament/Resources/CustomerResource/RelationManagers/ShipTosRelationManager.php database/migrations/2026_11_14_000002_phase193_pos_held_sales.php app/Models/PosHeldSale.php database/migrations/2026_11_15_000001_phase194_counter_pickup_default.php app/Pricing/PricingEngine.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.19.5'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.19.5"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_15_000001_phase194_counter_pickup_default.php" ] || { echo "CHECK FAILED: last migration is not 19.4"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => false" $L/database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php || { echo "CHECK FAILED: KY registry rule must seed disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  ! grep -rqi "highlevel\|gohighlevel" $L/resources/views || { echo "CHECK FAILED: vendor name in UI"; exit 1; }
  if [ -f secrets/ghl.env ]; then
    PIT=$(sed -n 's/^GHL_PIT=//p' secrets/ghl.env | tr -d '\r\n')
    [ -n "$PIT" ] && grep -rqF "$PIT" stage-up && { echo "CHECK FAILED: marketing token found in package"; exit 1; }
  fi
  for f in app/Marketing/MarketingInbound.php app/Http/Controllers/MarketingWebhookController.php app/Filament/Pages/MarketingInboundPage.php; do
    test ! -f $L/$f || { echo "CHECK FAILED: inbound sync file $f still shipped"; exit 1; }
  done
  ! grep -q "hooks/marketing\|marketing:pull" $L/routes/web.php $L/routes/console.php || { echo "CHECK FAILED: inbound route/schedule still present"; exit 1; }
  ! has_val "$GHL_LOC" $L/app $L/config $L/database $L/resources $L/routes || { echo "CHECK FAILED: live location id in package"; exit 1; }
  grep -q "resolveShipToId" $L/app/Livewire/CounterPos.php $L/app/Livewire/Driver/VanSale.php || { echo "CHECK FAILED: ship-to fix missing"; exit 1; }
  echo "Phase 19.5 checks OK"
fi
if [ "$PHASE" = "19.4" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_11_12_000001_phase191_marketing_inbound.php database/migrations/2026_11_11_000001_phase19_overrides_ghl.php \
           database/migrations/2026_11_13_000001_phase192_marketing_oneway.php app/Marketing/MarketingFields.php database/migrations/2026_11_14_000001_phase193_ship_to_default.php app/Filament/Resources/CustomerResource/RelationManagers/ShipTosRelationManager.php database/migrations/2026_11_14_000002_phase193_pos_held_sales.php app/Models/PosHeldSale.php database/migrations/2026_11_15_000001_phase194_counter_pickup_default.php app/Pricing/PricingEngine.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.19.4'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.19.4"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_15_000001_phase194_counter_pickup_default.php" ] || { echo "CHECK FAILED: last migration is not 19.4"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => false" $L/database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php || { echo "CHECK FAILED: KY registry rule must seed disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  ! grep -rqi "highlevel\|gohighlevel" $L/resources/views || { echo "CHECK FAILED: vendor name in UI"; exit 1; }
  if [ -f secrets/ghl.env ]; then
    PIT=$(sed -n 's/^GHL_PIT=//p' secrets/ghl.env | tr -d '\r\n')
    [ -n "$PIT" ] && grep -rqF "$PIT" stage-up && { echo "CHECK FAILED: marketing token found in package"; exit 1; }
  fi
  for f in app/Marketing/MarketingInbound.php app/Http/Controllers/MarketingWebhookController.php app/Filament/Pages/MarketingInboundPage.php; do
    test ! -f $L/$f || { echo "CHECK FAILED: inbound sync file $f still shipped"; exit 1; }
  done
  ! grep -q "hooks/marketing\|marketing:pull" $L/routes/web.php $L/routes/console.php || { echo "CHECK FAILED: inbound route/schedule still present"; exit 1; }
  ! has_val "$GHL_LOC" $L/app $L/config $L/database $L/resources $L/routes || { echo "CHECK FAILED: live location id in package"; exit 1; }
  echo "Phase 19.4 checks OK"
fi
if [ "$PHASE" = "19.3" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_11_12_000001_phase191_marketing_inbound.php database/migrations/2026_11_11_000001_phase19_overrides_ghl.php \
           database/migrations/2026_11_13_000001_phase192_marketing_oneway.php app/Marketing/MarketingFields.php database/migrations/2026_11_14_000001_phase193_ship_to_default.php app/Filament/Resources/CustomerResource/RelationManagers/ShipTosRelationManager.php database/migrations/2026_11_14_000002_phase193_pos_held_sales.php app/Models/PosHeldSale.php app/Pricing/PricingEngine.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.19.3'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.19.3"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_14_000002_phase193_pos_held_sales.php" ] || { echo "CHECK FAILED: last migration is not 19.3"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => false" $L/database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php || { echo "CHECK FAILED: KY registry rule must seed disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  ! grep -rqi "highlevel\|gohighlevel" $L/resources/views || { echo "CHECK FAILED: vendor name in UI"; exit 1; }
  if [ -f secrets/ghl.env ]; then
    PIT=$(sed -n 's/^GHL_PIT=//p' secrets/ghl.env | tr -d '\r\n')
    [ -n "$PIT" ] && grep -rqF "$PIT" stage-up && { echo "CHECK FAILED: marketing token found in package"; exit 1; }
  fi
  for f in app/Marketing/MarketingInbound.php app/Http/Controllers/MarketingWebhookController.php app/Filament/Pages/MarketingInboundPage.php; do
    test ! -f $L/$f || { echo "CHECK FAILED: inbound sync file $f still shipped"; exit 1; }
  done
  ! grep -q "hooks/marketing\|marketing:pull" $L/routes/web.php $L/routes/console.php || { echo "CHECK FAILED: inbound route/schedule still present"; exit 1; }
  ! has_val "$GHL_LOC" $L/app $L/config $L/database $L/resources $L/routes || { echo "CHECK FAILED: live location id in package"; exit 1; }
  echo "Phase 19.3 checks OK"
fi
if [ "$PHASE" = "19.2" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_11_12_000001_phase191_marketing_inbound.php database/migrations/2026_11_11_000001_phase19_overrides_ghl.php \
           database/migrations/2026_11_13_000001_phase192_marketing_oneway.php app/Marketing/MarketingFields.php app/Pricing/PricingEngine.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.19.2'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.19.2"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_13_000001_phase192_marketing_oneway.php" ] || { echo "CHECK FAILED: last migration is not 19.2"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => false" $L/database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php || { echo "CHECK FAILED: KY registry rule must seed disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  ! grep -rqi "highlevel\|gohighlevel" $L/resources/views || { echo "CHECK FAILED: vendor name in UI"; exit 1; }
  if [ -f secrets/ghl.env ]; then
    PIT=$(sed -n 's/^GHL_PIT=//p' secrets/ghl.env | tr -d '\r\n')
    [ -n "$PIT" ] && grep -rqF "$PIT" stage-up && { echo "CHECK FAILED: marketing token found in package"; exit 1; }
  fi
  for f in app/Marketing/MarketingInbound.php app/Http/Controllers/MarketingWebhookController.php app/Filament/Pages/MarketingInboundPage.php; do
    test ! -f $L/$f || { echo "CHECK FAILED: inbound sync file $f still shipped"; exit 1; }
  done
  ! grep -q "hooks/marketing\|marketing:pull" $L/routes/web.php $L/routes/console.php || { echo "CHECK FAILED: inbound route/schedule still present"; exit 1; }
  ! has_val "$GHL_LOC" $L/app $L/config $L/database $L/resources $L/routes || { echo "CHECK FAILED: live location id in package"; exit 1; }
  echo "Phase 19.2 checks OK"
fi
if [ "$PHASE" = "19.1" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_11_12_000001_phase191_marketing_inbound.php database/migrations/2026_11_11_000001_phase19_overrides_ghl.php \
           app/Marketing/MarketingInbound.php app/Models/MarketingLead.php app/Http/Controllers/MarketingWebhookController.php \
           app/Filament/Pages/MarketingInboundPage.php resources/views/filament/pages/marketing-inbound.blade.php app/Pricing/PricingEngine.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.19.1'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.19.1"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_12_000001_phase191_marketing_inbound.php" ] || { echo "CHECK FAILED: last migration is not 19.1"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => false" $L/database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php || { echo "CHECK FAILED: KY registry rule must seed disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  ! grep -rqi "highlevel\|gohighlevel" $L/resources/views || { echo "CHECK FAILED: vendor name in UI"; exit 1; }
  if [ -f secrets/ghl.env ]; then
    PIT=$(sed -n 's/^GHL_PIT=//p' secrets/ghl.env | tr -d '\r\n')
    [ -n "$PIT" ] && grep -rqF "$PIT" stage-up && { echo "CHECK FAILED: marketing token found in package"; exit 1; }
  fi
  echo "Phase 19.1 checks OK"
fi
if [ "$PHASE" = "19" ]; then
  L=stage-up/laravel
  M=database/migrations/2026_11_11_000001_phase19_overrides_ghl.php
  for f in $M database/migrations/2026_11_10_000001_phase182_ky_registry_disabled.php database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php \
           database/migrations/2026_11_08_000001_phase18_eligibility.php app/Models/CustomerPriceOverride.php app/Marketing/GhlClient.php \
           app/Notify/GhlNotifier.php app/Filament/Pages/MarketingSettingsPage.php app/Compliance/KyHempRegistry.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.19.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.19.0"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_11_000001_phase19_overrides_ghl.php" ] || { echo "CHECK FAILED: last migration is not 19"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => false" $L/database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php || { echo "CHECK FAILED: KY registry rule must seed disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  if [ -f secrets/ghl.env ]; then
    PIT=$(sed -n 's/^GHL_PIT=//p' secrets/ghl.env | tr -d '\r\n')
    [ -n "$PIT" ] && grep -rqF "$PIT" stage-up && { echo "CHECK FAILED: marketing token found in package"; exit 1; }
  fi
  echo "Phase 19 checks OK"
fi
if [ "$PHASE" = "18.2" ]; then
  L=stage-up/laravel
  M=database/migrations/2026_11_10_000001_phase182_ky_registry_disabled.php
  for f in $M database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php database/migrations/2026_11_08_000001_phase18_eligibility.php app/Compliance/Eligibility.php app/Compliance/KyHempRegistry.php \
           app/Models/StateRegistryEntry.php app/Filament/Pages/KyHempRegistryPage.php resources/views/filament/pages/ky-hemp-registry.blade.php \
           app/Filament/Pages/InventoryAtRisk.php app/Security/BrandworxProtection.php app/Credit/CreditLimit.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.18.2'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.18.2"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_10_000001_phase182_ky_registry_disabled.php" ] || { echo "CHECK FAILED: last migration is not 18.2"; exit 1; }
  grep -q "Audit::durable('eligibility_blocked'" $L/app/Services/SalesOrderService.php || { echo "CHECK FAILED: blocked confirm not durable-logged"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => true" $L/database/migrations/2026_11_08_000001_phase18_eligibility.php || { echo "CHECK FAILED: federal hemp placeholder must ship disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => false" $L/database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php || { echo "CHECK FAILED: KY registry rule must seed disabled"; exit 1; }
  echo "Phase 18.2 checks OK"
fi
if [ "$PHASE" = "18.1" ]; then
  L=stage-up/laravel
  M=database/migrations/2026_11_09_000001_phase181_ky_hemp_registry.php
  for f in $M database/migrations/2026_11_08_000001_phase18_eligibility.php app/Compliance/Eligibility.php app/Compliance/KyHempRegistry.php \
           app/Models/StateRegistryEntry.php app/Filament/Pages/KyHempRegistryPage.php resources/views/filament/pages/ky-hemp-registry.blade.php \
           app/Filament/Pages/InventoryAtRisk.php app/Security/BrandworxProtection.php app/Credit/CreditLimit.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.18.1'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.18.1"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_09_000001_phase181_ky_hemp_registry.php" ] || { echo "CHECK FAILED: last migration is not 18.1"; exit 1; }
  grep -q "Audit::durable('eligibility_blocked'" $L/app/Services/SalesOrderService.php || { echo "CHECK FAILED: blocked confirm not durable-logged"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => true" $L/database/migrations/2026_11_08_000001_phase18_eligibility.php || { echo "CHECK FAILED: federal hemp placeholder must ship disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  echo "Phase 18.1 checks OK"
fi
if [ "$PHASE" = "18" ]; then
  L=stage-up/laravel
  M=database/migrations/2026_11_08_000001_phase18_eligibility.php
  for f in $M app/Compliance/Eligibility.php app/Models/EligibilityRule.php app/Models/ProductCoa.php app/Models/VapeDirectoryListing.php \
           app/Filament/Resources/EligibilityRuleResource.php app/Filament/Pages/InventoryAtRisk.php resources/views/filament/pages/inventory-at-risk.blade.php \
           app/Security/BrandworxProtection.php app/Credit/CreditLimit.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.18.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.18.0"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_08_000001_phase18_eligibility.php" ] || { echo "CHECK FAILED: last migration is not 18"; exit 1; }
  grep -q "Eligibility::class)->checkOrder" $L/app/Services/SalesOrderService.php || { echo "CHECK FAILED: confirm() not eligibility-gated"; exit 1; }
  grep -q "'enabled' => false, 'verify_with_counsel' => true" $L/$M || { echo "CHECK FAILED: federal hemp placeholder must ship disabled"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  echo "Phase 18 checks OK"
fi
if [ "$PHASE" = "17.3" ]; then
  L=stage-up/laravel
  for f in app/Security/BrandworxProtection.php app/Credit/CreditLimit.php database/migrations/2026_11_07_000001_phase171_credit_limit.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.17.3'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.17.3"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_07_000001_phase171_credit_limit.php" ] || { echo "CHECK FAILED: unexpected new migration"; exit 1; }
  grep -q "BrandworxProtection::guardSave" $L/app/Models/User.php || { echo "CHECK FAILED: user guard missing"; exit 1; }
  grep -q "assign the brandworx_admin role" $L/app/Security/BrandworxProtection.php || { echo "CHECK FAILED: role-assign guard missing"; exit 1; }
  grep -q "customer_delete_blocked" $L/app/Models/Customer.php || { echo "CHECK FAILED: customer delete guard missing"; exit 1; }
  grep -q "change a customer" $L/app/Models/Customer.php || { echo "CHECK FAILED: credit limit guard missing"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  echo "Phase 17.3 checks OK"
fi
if [ "$PHASE" = "17.2" ]; then
  L=stage-up/laravel
  for f in app/Security/BrandworxProtection.php app/Credit/CreditLimit.php database/migrations/2026_11_07_000001_phase171_credit_limit.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.17.2'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.17.2"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_07_000001_phase171_credit_limit.php" ] || { echo "CHECK FAILED: unexpected new migration"; exit 1; }
  grep -q "BrandworxProtection::guardSave" $L/app/Models/User.php || { echo "CHECK FAILED: user guard missing"; exit 1; }
  grep -q "change a customer" $L/app/Models/Customer.php || { echo "CHECK FAILED: credit limit guard missing"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app $L/config $L/database $L/resources || { echo "CHECK FAILED: client MSA ID in package"; exit 1; }
  echo "Phase 17.2 checks OK"
fi
if [ "$PHASE" = "17.1" ]; then
  L=stage-up/laravel
  M=database/migrations/2026_11_07_000001_phase171_credit_limit.php
  for f in $M database/migrations/2026_11_06_000001_phase17_imports.php app/Credit/CreditLimit.php app/Credit/CreditLimitException.php app/Imports/ImportRunner.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -f $L/vendor/livewire/livewire/dist/livewire.min.js || { echo "CHECK FAILED: livewire dist missing"; exit 1; }
  grep -q "'version' => '0.17.1'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.17.1"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_07_000001_phase171_credit_limit.php" ] || { echo "CHECK FAILED: last migration is not 17.1"; exit 1; }
  grep -q "CreditLimit::class)->check" $L/app/Services/SalesOrderService.php || { echo "CHECK FAILED: confirm() not credit-gated"; exit 1; }
  ! has_val "$CLIENT_ID" $L/app/Msa/MulticatBuilder.php || { echo "CHECK FAILED: client MSA ID in app code"; exit 1; }
  echo "Phase 17.1 checks OK"
fi
if [ "$PHASE" = "17" ]; then
  L=stage-up/laravel
  M=database/migrations/2026_11_06_000001_phase17_imports.php
  for f in $M database/migrations/2026_11_05_000001_phase16_security_audit.php database/migrations/2026_11_04_000001_phase15_delivery_driver.php \
           app/Imports/ImportRunner.php app/Imports/SheetReader.php app/Imports/ImportRegistry.php app/Imports/ScheduledCostService.php app/Imports/Jobs/RunImportStep.php \
           app/Imports/Types/VendorCostsImport.php app/Imports/Types/ProductsImport.php app/Filament/Pages/Imports.php resources/views/filament/pages/imports.blade.php \
           app/Http/Controllers/ImportDownloadsController.php app/Models/ImportBatch.php app/Models/VendorItem.php app/Models/ScheduledCost.php app/Audit/Audit.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  test -d $L/vendor/openspout/openspout || { echo "CHECK FAILED: openspout missing"; exit 1; }
  grep -q "'version' => '0.17.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.17.0"; exit 1; }
  grep -q "costs:apply" $L/routes/console.php || { echo "CHECK FAILED: scheduled cost command missing"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_06_000001_phase17_imports.php" ] || { echo "CHECK FAILED: last migration is not 17"; exit 1; }
  LONG=$(grep -ohE "'[a-z_]+_(fk|idx|uq)'" $L/$M | tr -d "'" | awk 'length > 64')
  [ -z "$LONG" ] || { echo "CHECK FAILED: identifier over 64 chars: $LONG"; exit 1; }
  echo "Phase 17 checks OK"
fi
if [ "$PHASE" = "16" ]; then
  L=stage-up/laravel
  M=database/migrations/2026_11_05_000001_phase16_security_audit.php
  for f in $M database/migrations/2026_11_04_000001_phase15_delivery_driver.php app/Audit/Audit.php app/Providers/AuditServiceProvider.php app/Models/ActivityLog.php \
           app/Models/Concerns/HasActivity.php app/Filament/Resources/ActivityLogResource.php app/Filament/RelationManagers/HistoryRelationManager.php \
           app/Security/PasswordAdmin.php app/Filament/Pages/ChangePassword.php app/Http/Middleware/ForcePasswordChange.php resources/views/filament/pages/change-password.blade.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.16.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.16.0"; exit 1; }
  grep -q "AuditServiceProvider" $L/bootstrap/providers.php || { echo "CHECK FAILED: audit provider not registered"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_05_000001_phase16_security_audit.php" ] || { echo "CHECK FAILED: last migration is not 16"; exit 1; }
  LONG=$(grep -ohE "'[a-z_]+_(fk|idx|uq)'" $L/$M | tr -d "'" | awk 'length > 64')
  [ -z "$LONG" ] || { echo "CHECK FAILED: identifier over 64 chars: $LONG"; exit 1; }
  ! grep -qE "EditAction|DeleteAction|CreateAction" $L/app/Filament/Resources/ActivityLogResource.php || { echo "CHECK FAILED: activity log must be read-only"; exit 1; }
  echo "Phase 16 checks OK"
fi
if [ "$PHASE" = "15" ]; then
  L=stage-up/laravel
  M=database/migrations/2026_11_04_000001_phase15_delivery_driver.php
  for f in $M app/Delivery/SignatureService.php app/Delivery/DeliveryService.php app/Delivery/RoutePlanner.php app/Delivery/VanSaleService.php app/Delivery/DeliveryDays.php \
           app/Models/DeliverySignature.php app/Livewire/Driver/Today.php app/Livewire/Driver/StopScreen.php app/Livewire/Driver/VanSale.php app/Livewire/Driver/PickupSign.php \
           app/Filament/Pages/Modules.php app/Http/Responses/LoginResponse.php app/Http/Controllers/DeliveryDocsController.php resources/views/partials/signature-pad.blade.php \
           resources/views/docs/delivery-proof.blade.php resources/views/docs/_signatures.blade.php resources/views/layouts/driver.blade.php resources/views/filament/pages/modules.blade.php \
           database/migrations/2026_11_03_000002_phase141_rebate_gl.php database/seeders/Phase14Seeder.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.15.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.15.0"; exit 1; }
  grep -q "'require_signature' => true" $L/config/erp.php || { echo "CHECK FAILED: signatures must be required"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_04_000001_phase15_delivery_driver.php" ] || { echo "CHECK FAILED: last migration is not 15"; exit 1; }
  LONG=$(grep -ohE "'[a-z_]+_(fk|idx|uq)'" $L/$M | tr -d "'" | awk 'length > 64')
  [ -z "$LONG" ] || { echo "CHECK FAILED: identifier over 64 chars: $LONG"; exit 1; }
  grep -q "assertSigned" $L/app/Services/SalesOrderService.php || { echo "CHECK FAILED: deliver() not signature-gated"; exit 1; }
  for v in docs/delivery-proof docs/_signatures docs/invoice; do
    ! grep -qiE "powered-by|Powered by DistroSKU" $L/resources/views/$v.blade.php || { echo "CHECK FAILED: footer on printable $v"; exit 1; }
  done
  grep -q "powered-by" $L/resources/views/layouts/driver.blade.php || { echo "CHECK FAILED: driver screens need the footer"; exit 1; }
  echo "Phase 15 checks OK"
fi
if [ "$PHASE" = "14.1" ]; then
  L=stage-up/laravel
  M=database/migrations/2026_11_03_000002_phase141_rebate_gl.php
  for f in $M database/migrations/2026_11_03_000001_phase14_pricing.php app/Pricing/RebateLedger.php database/seeders/Phase14Seeder.php \
           app/Pos/PosPin.php app/Http/Middleware/PosAccess.php app/Livewire/PosLock.php app/Livewire/PosPinSetup.php app/Models/PosPinLogin.php \
           app/Filament/Resources/PosPinLoginResource.php resources/views/livewire/pos-lock.blade.php resources/views/livewire/pos-pin-setup.blade.php docs/REBATE_ACCOUNTING.md; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.14.1'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.14.1"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_03_000002_phase141_rebate_gl.php" ] || { echo "CHECK FAILED: last migration is not 14.1"; exit 1; }
  LONG=$(grep -ohE "'[a-z_]+_(fk|idx|uq)'" $L/$M $L/database/migrations/2026_11_03_000001_phase14_pricing.php | tr -d "'" | awk 'length > 64')
  [ -z "$LONG" ] || { echo "CHECK FAILED: identifier over 64 chars: $LONG"; exit 1; }
  grep -q "reverseForCreditMemo" $L/app/Services/CreditMemoService.php || { echo "CHECK FAILED: credit memos do not reverse rebates"; exit 1; }
  grep -q "PosAccess" $L/routes/web.php || { echo "CHECK FAILED: POS not behind PosAccess"; exit 1; }
  ! grep -q "Auth::login\|guard('web')->login" $L/app/Livewire/PosLock.php || { echo "CHECK FAILED: PIN must not create a web login"; exit 1; }
  for v in warehouse/pick-list warehouse/packing-list warehouse/bin-labels warehouse/count-sheet; do
    ! grep -qiE "powered-by|Powered by DistroSKU" $L/resources/views/$v.blade.php || { echo "CHECK FAILED: footer on printable $v"; exit 1; }
  done
  echo "Phase 14.1 checks OK"
fi
if [ "$PHASE" = "14" ]; then
  L=stage-up/laravel
  M=database/migrations/2026_11_03_000001_phase14_pricing.php
  for f in $M app/Pricing/PricingEngine.php app/Pricing/PriceQuote.php app/Pricing/RebateClaimService.php app/Models/PriceRule.php app/Models/Promotion.php \
           app/Models/CustomerGroup.php app/Models/PriceOverrideLog.php app/Models/BuydownAccrual.php app/Filament/Resources/PriceRuleResource.php \
           app/Filament/Resources/PromotionResource.php app/Filament/Resources/CustomerGroupResource.php app/Filament/Resources/PriceOverrideLogResource.php \
           app/Filament/Pages/RebateClaims.php resources/views/filament/pages/rebate-claims.blade.php database/migrations/2026_11_02_000001_phase13_return_approval.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.14.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.14.0"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_03_000001_phase14_pricing.php" ] || { echo "CHECK FAILED: last migration is not phase 14"; exit 1; }
  # explicit identifier names in the phase 14 migration must fit MySQL's 64-char limit
  LONG=$(grep -oE "'[a-z_]+_(fk|idx|uq)'" $L/$M | tr -d "'" | awk 'length > 64')
  [ -z "$LONG" ] || { echo "CHECK FAILED: identifier over 64 chars: $LONG"; exit 1; }
  grep -q "PricingEngine" $L/app/Models/SalesOrderLine.php || { echo "CHECK FAILED: sales lines not priced by the engine"; exit 1; }
  grep -q "PricingEngine" $L/app/Portal/PortalPricing.php || { echo "CHECK FAILED: portal not priced by the engine"; exit 1; }
  grep -q "PricingEngine" $L/app/Livewire/CounterPos.php || { echo "CHECK FAILED: POS not priced by the engine"; exit 1; }
  grep -q "BuydownAccrual::create" $L/app/Services/SalesOrderService.php || { echo "CHECK FAILED: buydown accruals not recorded"; exit 1; }
  for v in warehouse/pick-list warehouse/packing-list warehouse/bin-labels warehouse/count-sheet; do
    ! grep -qiE "powered-by|Powered by DistroSKU" $L/resources/views/$v.blade.php || { echo "CHECK FAILED: footer on printable $v"; exit 1; }
  done
  echo "Phase 14 checks OK"
fi
if [ "$PHASE" = "12" ]; then
  L=stage-up/laravel
  for f in database/migrations/2026_10_31_000001_phase11_counts_reorder.php database/migrations/2026_11_01_000001_phase12_pact_directory_shipping.php \
           app/Compliance/PactCategory.php app/Compliance/MsaDirectory.php app/Compliance/PactReportService.php app/Warehouse/ShippingService.php \
           app/Http/Controllers/PactController.php app/Filament/Resources/PactRegistrationResource.php app/Filament/Resources/PactReportResource.php \
           app/Filament/Resources/MsaDirectoryImportResource.php app/Filament/Resources/CarrierResource.php app/Filament/Resources/ShipmentResource.php \
           resources/views/compliance/pact-report.blade.php resources/views/compliance/pact-registration.blade.php resources/views/warehouse/shipping-label.blade.php \
           database/seeders/Phase12Seeder.php docs/PACT_ACT_AND_MSA_DIRECTORY.md app/Inventory/CountService.php; do
    test -f $L/$f || { echo "CHECK FAILED: $f missing"; exit 1; }
  done
  grep -q "'version' => '0.12.0'" $L/config/erp.php || { echo "CHECK FAILED: erp version is not 0.12.0"; exit 1; }
  [ "$(ls $L/database/migrations | tail -1)" = "2026_11_01_000001_phase12_pact_directory_shipping.php" ] || { echo "CHECK FAILED: last migration is not phase 12"; exit 1; }
  grep -q "MsaDirectory::listed" $L/app/Services/ComplianceChecker.php || { echo "CHECK FAILED: directory not enforced in ComplianceChecker"; exit 1; }
  grep -q "PactRegistration::current" $L/app/Services/ComplianceChecker.php || { echo "CHECK FAILED: PACT registration not enforced"; exit 1; }
  grep -q "1716E" $L/app/Warehouse/ShippingService.php || { echo "CHECK FAILED: postal block missing"; exit 1; }
  grep -q "'pact' => \['view' => \[\], 'manage' => \[\]\]" $L/config/erp.php || { echo "CHECK FAILED: pact must be admin-level"; exit 1; }
  grep -q "Phase12Seeder" $L/database/seeders/CoreSeeder.php || { echo "CHECK FAILED: CoreSeeder does not call Phase12Seeder"; exit 1; }
  grep -q "dompdf.public_path" $L/app/Providers/AppServiceProvider.php || { echo "CHECK FAILED: 7.2 dompdf fix missing"; exit 1; }
  test ! -e $L/resources/views/partials/powered-by-pdf.blade.php || { echo "CHECK FAILED: printable footer partial is back"; exit 1; }
  for v in warehouse/pick-list warehouse/packing-list warehouse/bin-labels warehouse/count-sheet warehouse/shipping-label compliance/pact-report compliance/pact-registration reports/dunning-statements; do
    ! grep -qiE "powered-by|Powered by DistroSKU" $L/resources/views/$v.blade.php || { echo "CHECK FAILED: footer on printable $v"; exit 1; }
  done
  ! grep -rqiE "Http::|curl_|guzzle" $L/app/Warehouse/ShippingService.php $L/app/Compliance || { echo "CHECK FAILED: no live carrier / state API calls"; exit 1; }
  echo "Phase 12 files OK"
fi
cp "$README" stage-up/README_UPGRADE.md
# ---- self-deploy: endpoint + manifest (seeders / version). Only the PUBLIC key ships (laravel/config/deploy.php). ----
cp deploy/deploy.php stage-up/public_html/deploy.php && php -l stage-up/public_html/deploy.php >/dev/null
VERSION="phase${PHASE}-$(date +%Y%m%d-%H%M%S)"
php -r '$s=array_values(array_filter(explode(" ", $argv[2]))); echo json_encode(["format"=>1,"phase"=>$argv[1],"version"=>$argv[3],"built_at"=>date("c"),"seeders"=>$s], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);' "$PHASE" "$SEEDERS" "$VERSION" > stage-up/deploy-manifest.json
cp stage-up/deploy-manifest.json stage-up/laravel/deploy-manifest.json
grep -qF "$(tr -d '\n' < secrets/deploy_ed25519_public)" stage-up/laravel/config/deploy.php || { echo "CHECK FAILED: config/deploy.php public key != secrets/deploy_ed25519_public"; exit 1; }
for f in secrets/deploy_hmac_secret secrets/deploy_ed25519_secret secrets/adminpass; do
  [ -f "$f" ] || continue
  if grep -rqF "$(tr -d '\n' < "$f")" stage-up/; then echo "CHECK FAILED: secret $f found in package"; exit 1; fi
done
test ! -e stage-up/laravel/.deploy-secret
test ! -e stage-up/laravel/.env && test ! -e stage-up/laravel/storage
! grep -rIl --exclude-dir=vendor 'S0cM0nk3y' stage-up/ || { echo "SECRET FOUND"; exit 1; }
(cd stage-up && zip -qr ../$OUT laravel public_html README_UPGRADE.md deploy-manifest.json)
ls -la $OUT
