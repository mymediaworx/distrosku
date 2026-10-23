# DistroSKU / Vega ERP — Developer Handoff for Claude

You are joining as an additional developer on the Vega Distribution ERP ("DistroSKU"), repo `mymediaworx/distrosku`.
Brandworx Dev owns releases: it reviews, merges, builds, and deploys. You contribute via pull requests only.

## 1. What the product is
A wholesale distribution ERP for a Kentucky tobacco / hemp / vape distributor (Vega Distribution).

| Area | What it does |
|---|---|
| POS (`/pos`) | Counter point-of-sale (Livewire), PIN lock/tablet mode. |
| Sales orders | Order-to-cash: save & confirm, auto-invoice on delivery, record payment (cash, check #, card, ACH, on account; partials), "deliver, invoice & collect" in one step, Paid/Partial chips, Receivables page. On-account over the credit limit needs an admin override. |
| Customers + ship-tos | **Counter pickup is the default**; a ship-to is needed only when Delivery is chosen, and only delivery orders are routed. Customer types: C-store, Liquor store, Smoke shop, Retailer, Restaurant, Food truck, Wholesale distributor, Other (old value kept in `type_legacy`). Customer licenses. |
| Customer portal (`/portal`) | Promotions / New / Featured sections (driven by product checkboxes), Buy again, collapsible category catalog. |
| Delivery / Driver / Van sales | Routes, driver "today" app (`/driver`), van sales. |
| Purchasing | POs with expected arrival, carrier, tracking numbers + Track links; Expected Inbound page; bell alerts + 6:30am digest to Receiving team → Warehouse users → owners/admins. Warehouse users may edit tracking/ETA only. |
| Warehouse / Inventory | Receive, putaway, pick, pack, counts, labels (`/warehouse`), lots, bins. |
| Counter Pickup board | Staff work queue for pickup orders (own module after Inventory). |
| Accounting / AP | GL posting, vendor bills, bank reconciliation, period close. |
| Compliance | Hemp/THC rules (Rules Hub), licenses, MSA fix list. |
| Tax filings | KY tobacco / cigarette excise EDI XML (XSDs + guides in `docs/`), PACT Act reports. |
| MSA Weekly | MULTICAT fixed-width `.TOB` export — spec in `docs/msa_tob_format.md`. |

## 2. Stack
- Laravel 12, Filament 3 (admin at `/admin`), Livewire 3, MySQL/MariaDB (MariaDB in prod, SQLite in-memory for tests).
- Production: SiteGround, Managed **PHP 8.2**. **All code must run on PHP 8.2** — `composer.json` pins `config.platform.php` to `8.2.0`. Don't use 8.3+ features (typed class constants, `json_validate`, `#[\Override]`, etc.) or bump packages that need >8.2.
- Queue: `database` driver, drained by the scheduler.

## 3. Repo layout
| Path | What |
|---|---|
| `laravel/` | The application (this is what ships). |
| `laravel/app/Filament/Resources`, `Pages` | Admin CRUD and custom pages (Receivables, ExpectedInbound, CounterPickupBoard, MsaWeekly, tax reports…). |
| `laravel/app/<Domain>/` | Domain services: `Sales`, `Payments`, `Purchasing`, `Warehouse`, `Inventory`, `Delivery`, `Accounting`, `Tax`, `Msa`, `Compliance`, `Portal`, `Pos`, `Pricing`, `Credit`, `RulesHub`, … |
| `laravel/app/Livewire` | POS, warehouse, driver, portal screens. |
| `laravel/app/Navigation/NavOrder.php` | Module/sidebar order. |
| `laravel/config/erp.php` | App config incl. `'version'`. |
| `laravel/routes/web.php` | `/pos`, `/warehouse/*`, `/driver/*`, `/portal`, etc. |
| `laravel/database/migrations` | Phase-named migrations (e.g. `..._phase206_customer_screen.php`). |
| `laravel/tests/Feature` | Phase-numbered tests (`Phase205ModulesBillLinesTest.php`). |
| `build-upgrade.sh`, `README_UPGRADE.md` | Release packaging — Brandworx Dev only. |
| `docs/` | Roadmap, FRD gap list, KY EDI guides/XSDs, MSA format. |

## 4. Module order (25 items, `NavOrder.php`)
POS, Sales Orders, Customers, Payments, Pricing, Promotions, Products, Vendors, Purchasing, Warehouse, Inventory, Counter Pickup |
Delivery, Driver, Van Sales, Accounting, Bills (AP) | Reports, Tax Filings, MSA Weekly, PACT Reports, Compliance | Users, Settings, Imports.

## 5. Conventions
- Version lives in `config/erp.php` → `'version'`. Don't bump it; Brandworx Dev assigns versions.
- Migrations must be **idempotent / re-runnable** (`Schema::hasTable` / `hasColumn` guards), index names ≤ 64 chars.
- Tests named by phase: `Phase<NNN><Feature>Test.php`. Tests must pass on **SQLite and MariaDB**.
- Follow existing Filament resource/page patterns; keep business logic in domain services, not in forms.

## 6. Current state
- Live: **0.20.5** (Counter Pickup module, wider vendor-bill Lines).
- In progress: **0.20.6** — Edit Customer screen rework (Terms dropdown, price groups, EIN/state tax ID, licenses on the customer screen, license types from Settings) + a full responsive/mobile UI polish pass.
  **Avoid changes to the Customer resource, customer licenses, Settings, and the Filament theme/CSS/layout until 0.20.6 is merged.**
- Queued: MSA Weekly export alignment to the real MULTICAT file (0.20.x).

## 7. Running tests locally
```bash
cd laravel
composer install
cp .env.example .env && php artisan key:generate
php artisan test                    # SQLite in-memory (phpunit.xml)
# MariaDB: point DB_CONNECTION=mariadb + DB_* at a local throwaway DB, then vendor/bin/phpunit
```
Use PHP 8.2 locally if you can (to match production).

## 8. Contribution rules (mandatory)
1. Branch from `main` (`feature/<short-name>`), open a PR to `main`. **Never push to `main`.**
2. **Never create, move, or delete tags. Never force-push.**
3. Brandworx Dev reviews, merges, builds zips, and deploys. **You never deploy, build release zips, or touch any server.**
4. Never commit secrets: `.env`, tokens, API keys, passwords, credentials, `secrets/`, customer data, or the `msa-sample/` folder.
5. No real customer/vendor data in tests or fixtures — use factories and fake names.
6. Keep PHP 8.2 compatible.
7. Every change ships with tests; full suite green before requesting review.
8. Small, focused PRs with a clear description of what changed and why.

## 9. Good starter tasks (open roadmap — check `docs/ROADMAP_2026-10-21.md`)
- **Cash handling & driver settlement:** till open/close, drops, over/short, Z-report, driver cash settlement with deposit slips, NSF returned checks, IRS 8300 cumulative cash (>$10k).
- **Sales tax & exemptions:** resale/exemption certs with expiry, tax-exempt flags per customer & ship-to, tax on invoices. *(Touches Customer — wait for 0.20.6.)*
- **Cigarette tax stamps** (admin toggle, off by default): purchase/inventory/affixing, reconciliation, block cross-state sale of stamped stock.
- **Quick-tools PIN & approvals:** named login or PIN, activity log, new SKUs pending manager approval, tax class locked to approvers, duplicate-UPC detection.
- **Saved payment methods via Authorize.net Customer Profiles:** store only the processor's profile/payment-profile IDs, brand, last 4 and expiry. **Never store raw card or bank account numbers.** Build behind a config flag; API keys aren't available yet, so mock the gateway in tests.
