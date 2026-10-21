# Vega ERP — Phase 1 deploy (vegatest1.distrosku.com, SiteGround)

TEST DATA ONLY. Authorize.net is sandbox-only and not wired yet. No secrets are in this package.

## Layout on SiteGround
```
/home/customer/www/vegatest1.distrosku.com/
├── laravel/        <- the app (vendor included, built with composer --no-dev, PHP 8.2+)
└── public_html/    <- web root (index.php points to ../laravel)
```
`bootstrap/app.php` automatically uses the sibling `public_html` as the public path.

## Steps
1. Site Tools > Devs > PHP Manager: PHP 8.3 (8.2 minimum) for vegatest1.distrosku.com.
2. Site Tools > Site > MySQL: confirm the site database and its database user (All privileges). Password is NOT in this package — type it into the setup form.
3. File Manager: upload `vega-erp-phase1.zip` to `/home/customer/www/vegatest1.distrosku.com/`, extract. It creates `laravel/` and `public_html/` (merge with the existing public_html; remove SiteGround's default index.html if present).
4. Visit `https://vegatest1.distrosku.com/setup-<TOKEN>.php?token=<TOKEN>` (exact filename is in `public_html/`).
   Fill DB password, admin name/email/password (12+ chars). Leave "Load TEST demo data" ticked for the prototype.
   It writes `.env`, generates APP_KEY, migrates, seeds chart of accounts + 2 warehouses (Main Lexington, Beverage) + starter compliance rules, optional demo data, storage link, caches, permissions — then deletes itself. If it cannot delete itself, delete it in File Manager.
5. Log in: `https://vegatest1.distrosku.com/admin` · Counter POS: `https://vegatest1.distrosku.com/pos`.
6. Cron (Site Tools > Devs > Cron Jobs), every minute:
   `cd ~/www/vegatest1.distrosku.com/laravel && php artisan schedule:run >> /dev/null 2>&1`

## Re-deploying code later
Upload a new zip over `laravel/` (keep `laravel/.env` and `laravel/storage/`), then run migrations (SSH: `php artisan migrate --force && php artisan optimize`).

## Notes
- Starter compliance rules are marked "PROTOTYPE - verify before use". They are placeholders until the Rules Hub feed exists.
- Demo users (test-warehouse/sales/driver@example.test) get random passwords; reset them in Admin > Users if needed.
