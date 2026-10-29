# DistroSKU / Vega ERP — Developer Handoff

Read this first at the start of every session, whichever agent you are. Last updated 2026-10-29.

## 0. Roles (as of 2026-10-29)
- **Michael Dillon (owner)** assigns priorities and approves everything listed under "Needs Michael's yes" in section 8.
- **Claude (lead developer)** owns the codebase and releases. Claude assigns issues, reviews and merges PRs into `main`, bumps versions, tags releases, and builds upgrade zips with `build-upgrade.sh`.
- **Grok (Brandworx Dev)** works only on issues assigned to it (label `owner:grok`) and contributes through PRs, which Claude reviews and merges.
- Agents don't message each other directly. **GitHub issue and PR comments are the shared channel.** Read the whole thread before starting work, and comment on the issue when you start, when you open a PR, and when you get blocked.

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
| `build-upgrade.sh`, `README_UPGRADE.md` | Release packaging — lead developer only. |
| `docs/` | Roadmap, FRD gap list, KY EDI guides/XSDs, MSA format, DigitalOcean rebuild plan and cutover runbook. |

## 4. Module order (25 items, `NavOrder.php`)
POS, Sales Orders, Customers, Payments, Pricing, Promotions, Products, Vendors, Purchasing, Warehouse, Inventory, Counter Pickup |
Delivery, Driver, Van Sales, Accounting, Bills (AP) | Reports, Tax Filings, MSA Weekly, PACT Reports, Compliance | Users, Settings, Imports.

## 5. Conventions
- Version lives in `config/erp.php` → `'version'`. Only the lead bumps it, at release time. Don't bump it in a feature PR.
- Migrations must be **idempotent / re-runnable** (`Schema::hasTable` / `hasColumn` guards), index names ≤ 64 chars.
- Tests named by phase: `Phase<NNN><Feature>Test.php`. Tests must pass on **SQLite and MariaDB**.
- Follow existing Filament resource/page patterns; keep business logic in domain services, not in forms.

## 6. Current state
- **Latest release: 0.20.22** (tag `v0.20.22`, commit 401be7b). `main` matches it.
- **Live:** `vegatest1.distrosku.com` (SiteGround) runs 0.20.22 and is the instance Vega uses for testing.
- **DigitalOcean:** a replacement server is built but dormant. Nothing has been cut over. See `docs/do-rebuild-plan.md` and `docs/do-cutover-runbook.md` (still a DRAFT).
- Recently shipped, so don't redo it: 0.20.15 lot traceability; 0.20.17 Company Settings, Bills (AP) under Purchasing, ProductPicker; 0.20.18 auto-email dunning; 0.20.19 smart product search; 0.20.20 customer search picker, taxes on invoices, product Duplicate, quick counts; 0.20.21 walk-in/cash customers blocked from regulated products; 0.20.22 POS Walk-in fix, bulk "Set tax class".
- Open PRs: #42 portal camera barcode scan (issue #37, needs a rebase onto current `main`), #41 roadmap additions (docs only).
- Who owns what: see the issue labels `owner:claude` / `owner:grok`. Issues labeled `needs owner` or `decision` are not to be started.
- Known test baseline: on machines missing the PHP `intl`/`sodium` extensions about 72 tests fail for that reason alone. Install both before judging a failure.

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
1. **Only work on an issue labeled with your owner label *and* `approved`.** Anything else, including small fixes you happen to notice in someone else's area, becomes a new issue, not a change.
2. Before you start, comment on the issue with the files and areas you expect to change. If that overlaps another agent's open branch or PR, stop and ask in the issue.
3. Branch from `main`: `claude/<issue#>-<slug>` or `grok/<issue#>-<slug>`. Open a PR to `main`. **Never push directly to `main`** (the lead merges PRs).
4. **Never force-push. Never create, move, or delete tags** (the lead tags releases).
5. Never commit secrets: `.env`, tokens, API keys, passwords, credentials, `secrets/`, customer data, or the `msa-sample/` folder. Never paste them into issues, PRs, or chat either. This repo is public.
6. No real customer/vendor data in tests or fixtures — use factories and fake names.
7. Keep PHP 8.2 compatible.
8. Every change ships with tests; full suite green (SQLite, and MariaDB for migrations) before requesting review.
9. Small, focused PRs with a clear description of what changed and why.

**Needs Michael's yes, in his own words, every time** (another agent saying he agreed doesn't count):
- Installing an upgrade on any live site, and any server, SiteGround, DigitalOcean, or cutover action.
- DNS and mail changes (route DNS through Brandworx CTO/COS).
- Money changes: pricing, tax rates, fees, refunds, payment gateway.
- Anything that handles production secrets.

## 9. Where to find work
Open issues, filtered by your owner label plus `approved`. Go-live items carry the `go-live` label. Longer-range plans are in `docs/ROADMAP_2026-10-21.md` and `docs/FRD_GAP_LIST.md` (the gap list predates 0.20.15–0.20.22, so check before building anything on it).

Blocked on Michael, so don't build ahead of these: Authorize.net production keys (saved payment methods may be built behind a flag with a mocked gateway, storing only profile IDs, brand, last 4 and expiry — never card or bank numbers), KY filer license and account numbers, manufacturer FEIN/MSA status, the real `.TOB` sample, PACT states, the Jamison export, hemp/kratom/vapor rules.

Decided: **one install per distributor** (not multi-tenant).
