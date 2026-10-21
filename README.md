# Vega ERP (DistroSKU)

Wholesale distribution ERP for Vega Distribution (KY): orders, pricing, inventory/lots, warehouse, delivery, POS, AR/AP and GL,
KY tobacco/cigarette excise reporting, MSA/PACT compliance, customer portal, and the BrandWorx Marketing (one-way contact sync) module.

## Layout
| Path | What |
|---|---|
| `laravel/` | The Laravel application (this is what is deployed). |
| `build-upgrade.sh` | Builds `vega-erp-phase<X>-upgrade.zip` for an existing install, with per-phase checks and a secret scrub. |
| `build.sh`, `setup.tpl.php` | First-install package and its web installer. |
| `upgrade.tpl.php` | Web upgrader template (copied as `upgrade-<token>.php` at build time; never committed). |
| `deploy/`, `deploy.sh` | Signed deploy endpoint/client (secrets are read from `secrets/`, never committed). |
| `README_UPGRADE.md`, `README_DEPLOY.md` | Operator instructions per phase / first install. |
| `docs/` | FRD gap list, roadmap, KY EDI guides and XSDs, MSA format specs. |

## Stack
PHP 8.3+, Laravel 12, Filament 3, Livewire 3. MariaDB 11.8 in production (SiteGround), SQLite for local tests.
Queue: `database` driver, drained every minute by `schedule:run` (cron) via `queue:work --stop-when-empty`.

## Run the tests
```bash
cd laravel
composer install
cp .env.example .env && php artisan key:generate
php artisan test                       # SQLite in-memory (phpunit.xml)
# MariaDB:
DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_DATABASE=vega_test DB_USERNAME=vtest DB_PASSWORD=vtest DB_URL= vendor/bin/phpunit
```

## Build an upgrade
1. Bump `'version'` in `laravel/config/erp.php`; add a re-runnable migration (index names <= 64 chars).
2. Add the phase to the seeder `case` and a check block in `build-upgrade.sh`; add a section to `README_UPGRADE.md` (header "Phase X UPGRADE").
3. Keep tests green on SQLite and MariaDB, then `bash build-upgrade.sh <phase>` -> `vega-erp-phase<phase>-upgrade.zip` (+ sha256).
4. Commit and tag `v0.<phase>` here.

Secrets (tokens, client IDs, deploy keys, `.env`) live only in `secrets/` and the server `.env`; both are git-ignored.
