# distrosku.com migration inventory (SiteGround -> DigitalOcean `distrosku-prod-1`)

Status: **Phase 2 rehearsal done (2026-10-27 late evening, ET).** All five hosts are deployed on `distrosku-prod-1` with restored data, enabled in nginx **behind the basic-auth gate and noindex**, reachable only via `curl --resolve` rehearsal (DNS still points at SiteGround). Nothing has been changed in DNS; mail stays blocked (`MAIL_MAILER=log`, outbound 25 denied). Section 7 has the per-host result. One narrow exception to "nothing at SiteGround": a single manual `dba:poll` from the DO copy of DBA sent one signed health request to the live vegatest1 (it failed signature, no state change there, DO-side failure counter reset).
Authorised by Michael on 2026-10-27: "Start the migration of distrosku as well. All subdomains."
No secrets are in this file. Per-site DB credentials are in root-only files on the server (`/root/distrosku-sites/<name>.env`, mode 600) and in the Grok box secrets folder (`secrets-sg/distrosku-do-<name>.env`).
This complements `do-rebuild-plan.md` and `do-cutover-runbook.md` (read those first; this file adds the per-host detail for the whole zone).

## 1. Hostnames found (outside-in, 2026-10-27)

Sources: authoritative DNS (`ns1/ns2.siteground.net`, SOA serial 32; queried from the server because the Grok box resolver is sandboxed), HTTP/TLS probes, repo docs and Grok memory, Namecheap registrar record (read-only). crt.sh returned nothing usable, so the list is DNS-derived; every name below resolves to SiteGround `35.212.119.29`. No AAAA, no CAA, no wildcard record exists (random names do not resolve).

| Host | DNS | What it runs | Moves? |
|---|---|---|---|
| `distrosku.com` (+ `www`) | A 35.212.119.29 | Static one-page marketing site ("DistroSKU - Light ERP for Tobacco & Cash-and-Carry Distributors", Tailwind CDN, `logo.jpg`, a `robots.txt` with Crawl-delay). `/index.php`, `/admin`, `/login` are 404. SiteGround account root also has a `laravel` folder next to `public_html` for this site (purpose not confirmed; **do not touch it**). | Yes (web) |
| `vegatest1.distrosku.com` | A | **Vega ERP** (Laravel 12 / Filament 3), the live Vega Distribution instance. `/admin` login, `/up` 200, `/dba-api/status` (signed, 405 to GET), session cookie `vega-erp-session`. | Yes (web), highest risk |
| `dba.distrosku.com` (+ `www.dba`) | A | **DistroSKU DBA 0.1.x** instance manager (Laravel 12 / Filament 3), `/login`, cookie `distrosku-dba-session`. Holds the signed-link secrets for each ERP. | Yes (web) |
| `staging.distrosku.com` | A | SiteGround "Under construction" placeholder only (nothing deployed). | Yes (empty); see 3.4 |
| `app.distrosku.com` (+ `www.app`) | A | Legacy plain-PHP app "DistroSKU - Multi-Tenant SKU Management": `login.php` (username/password), `assets/css/style.css`, PHPSESSID. Not Laravel, not the ERP. Source and database are unknown. | Yes (web), needs inspection |
| `mail.` `ftp.` `autodiscover.` `autoconfig.` | A (same IP) | SiteGround mailbox/FTP/auto-config endpoints (cert is SiteGround's `gvam1314.siteground.biz`; ports 21/993 answer). | **No. Stay at SiteGround.** |
| apex MX / TXT | MX 10/20/30 `mx10/20/30.antispam.mailspamprotection.com`; SPF `v=spf1 +a +mx include:distrosku.com.spf.auto.dnssmarthost.net ~all`; `_dmarc` `v=DMARC1; p=none; aspf=r; adkim=r;`; `default._domainkey` (dnssmarthost DKIM) | @distrosku.com mailboxes hosted at SiteGround | **No. Do not change.** |

TLS today: SiteGround Let's Encrypt wildcards `*.distrosku.com` (expires 2027-01-17), `*.dba.distrosku.com`, `*.app.distrosku.com`. These cannot be exported, so new certificates are needed on the server (section 5).

### Open questions for the SiteGround/registrar session
1. **Where is the DNS zone edited?** Namecheap lists `distrosku.com` (registered 2026-10-19, expires 2027-10-19, auto-renew on) with its default nameservers, but the live NS records are SiteGround (`ns1/ns2.siteground.net`). The zone is therefore edited in SiteGround Site Tools > Domain > DNS Zone Editor (or the registrar delegation was changed and is not showing). Confirm before T-7.
2. What is in the account's `distrosku.com/laravel` folder, and is anything in `distrosku.com/public_html` dynamic? (Read-only look in File Manager; do not rename, move or edit.)
3. Who uses `app.distrosku.com`, and which DB does it use? If nobody, decide whether to migrate or retire it.
4. Is the `*.app` / `*.dba` wildcard cert a sign of tenant subdomains? No such DNS names exist today (`x.app`, `x.dba` do not resolve), so none are planned here.

## 2. What was prepared on `distrosku-prod-1` (165.245.139.132)

All sites are **dormant**: the nginx vhosts exist in `sites-available` but are not symlinked into `sites-enabled`, so no public request can reach them (the catch-all still returns 444). DNS still points at SiteGround. Rehearsal behind basic auth is possible with `distrosku-site enable <host>` and `curl --resolve`.

| Host | Directory (owner `deploy:www-data`) | Layout | DB / user (credentials in root-only env file) | php-fpm pool / socket | vhost |
|---|---|---|---|---|---|
| distrosku.com, www | `/var/www/distrosku.com` | `public_html/`, `laravel/` | `dsku_main` / `dsku_main` (`main.env`; may stay unused) | `dsku-main`, max 4 | `/etc/nginx/sites-available/distrosku.com.conf` (static + optional `index.php`) |
| vegatest1.distrosku.com | `/var/www/vegatest1.distrosku.com` | `public_html/`, `laravel/` | `dsku_vegatest1` (`vegatest1.env`) | `dsku-vegatest1`, max 8 | `vegatest1.distrosku.com.conf` (Laravel front controller only; same hardening as do-staging) |
| dba.distrosku.com, www.dba | `/var/www/dba.distrosku.com` | `public_html/`, `laravel/` | `dsku_dba` (`dba.env`) | `dsku-dba`, max 8 | `dba.distrosku.com.conf` (Laravel) |
| staging.distrosku.com | `/var/www/staging.distrosku.com` | `public_html/`, `laravel/` | `dsku_staging` (`staging.env`) | `dsku-staging`, max 8 | `staging.distrosku.com.conf` (Laravel) |
| app.distrosku.com, www.app | `/var/www/app.distrosku.com` | `public_html/` only | `dsku_app` (`app.env`) | `dsku-app`, max 8 | `app.distrosku.com.conf` (plain PHP: any `.php` under the docroot executes; dotfiles, env/sql/bak/log files denied) |

Common to every vhost: port 80 serves only `/.well-known/acme-challenge/` (webroot `/var/www/certbot`) and 301s to HTTPS; port 443 uses a **self-signed placeholder** (`/etc/ssl/distrosku-placeholder/`) until the real cert is issued; security headers; `server_tokens off`; a **gate** include `/etc/nginx/distrosku-gate.d/<host>.conf` (basic auth with `/etc/nginx/distrosku.htpasswd` + `X-Robots-Tag: noindex`), emptied at cutover.

Databases: utf8mb4, one DB and one user per app (`ALL PRIVILEGES` on its own DB only, needed for migrations; users exist for `localhost` and `127.0.0.1`). The existing `distrosku_staging` DB and the `do-staging` site on `165-245-139-132.sslip.io` were not touched.

Helper (root): `distrosku-site status | enable <host> | disable <host> | gate on|off <host> | cert <host>`. `cert` issues a Let's Encrypt cert via the webroot challenge and swaps the placeholder for the real paths.

Not done on purpose: no cron, queue worker or nightly dump for the new sites (added per host at cutover, step list below); mail block untouched (ufw still denies outbound 25; `staging-mail-guard` still runs for the old staging DB only); no DNS, SiteGround or billable DigitalOcean changes. Application code is not deployed yet.

## 3. Per-host data to pull from SiteGround and cutover steps

SiteGround paths follow the repo READMEs: `/home/customer/www/<host>/{laravel,public_html}`. Transfer files by `scp` to the server directly from the SiteGround side or via the box; never through chat or e-mail. Treat every zip/dump as a secret (it contains `.env` data). Common cutover wrapper for each host is the runbook (T-7 TTL 300, freeze, final backup, restore, verify, DNS flip, rollback = flip the A record back, SiteGround copy stays intact).

### 3.1 vegatest1.distrosku.com (Vega ERP) - do this first, own freeze window
Pull:
1. A fresh `erp:backup` zip (instance manager at dba.distrosku.com > Backup now > download, or `php artisan erp:backup --trigger=manual`). It holds the DB and `storage/app` uploads. Verify with `erp:backup --list --verify`; note name and sha256.
2. The values from `laravel/.env` that must be carried or re-entered, without printing them: `ERP_INSTANCE`, `ERP_COMPANY`, `ERP_HOME_STATE`, `ERP_DBA_SECRET` (link to dba), SMTP/mail settings (stored encrypted in the DB: the new server has a different `APP_KEY`, so re-enter them in Company Settings > Email, or copy the source `APP_KEY` into the server `.env` before restoring; see runbook section 1.3).
3. Release zip: current version's upgrade zip from the repo (same one used for do-staging).
Server: unzip into `/var/www/vegatest1.distrosku.com/{laravel,public_html}`, create `.env` from `/root/distrosku-sites/vegatest1.env` (DB_HOST 127.0.0.1), `key:generate` (or carried key), `storage:link`-equivalent per do-staging, `migrate --force`, `erp:restore <zip> --force` (not `--with-env`), `optimize`.
Cutover extras: add `* * * * * cd /var/www/vegatest1.distrosku.com/laravel && php artisan schedule:run` for www-data and a Supervisor `queue:work` program (or rely on the scheduler drain), extend `distrosku-dbdump` to this DB and `storage/app`, turn the gate off, issue cert, flip DNS A, re-check `/up` and `/dba-api/status`, then update the instance URL/secret in DBA if it changed. Email stays off until Michael approves (outbound 25 closed, SMTP via approved relay).

### 3.2 dba.distrosku.com (DBA instance manager) - after vegatest1 is stable
Pull:
1. A SQL dump of the DBA database (Site Tools > MySQL > phpMyAdmin Export, or `mysqldump`).
2. `laravel/.env` **`APP_KEY`** (copy without printing). The ERP link secrets and other encrypted columns in the DBA DB are encrypted with it; without the same key every instance must be re-linked (*New link secret* in the manager and paste into each ERP's `ERP_DBA_SECRET`). Carrying the key is simpler.
3. `.env` non-secret settings to recreate: `DBA_OWNER_EMAIL`, `DBA_ALERT_EMAILS`, `DBA_GITHUB_REPO`/`DBA_GITHUB_TOKEN` (if used), `DBA_LATEST_VERSION`, mail settings.
4. The DBA release zip (`distrosku-dba-0.1.1.zip`, in repo `distrosku-dba/`).
Server: unzip to `/var/www/dba.distrosku.com`, `.env` from `dba.env` + carried `APP_KEY`, import the dump into `dsku_dba`, `migrate --force`, `optimize`, `filament:optimize`; cron `* * * * * ... schedule:run` (polls every 5 minutes). Note DBA is the monitor: after cutover verify it can reach `https://vegatest1.distrosku.com` (whichever host serves it at that time) and that both sides use the right URL. Stop the SiteGround cron for DBA only when DNS has flipped, to avoid two managers polling and double alert emails.

### 3.3 distrosku.com + www (marketing site)
Pull: everything under `/home/customer/www/distrosku.com/public_html/` (index.html, `logo.jpg`, `robots.txt`, any other static assets). Read-only listing of the `laravel` folder and decision whether it is used by the apex (if yes, it also needs a DB export). Do **not** change that folder.
Server: copy into `/var/www/distrosku.com/public_html`, keep `robots.txt`, enable vhost, test with `--resolve`. Cutover: flip the apex and `www` A records; keep `www` as an alias (no redirect change) unless Michael wants one.

### 3.4 staging.distrosku.com
Nothing to pull (SiteGround shows only the default under-construction page). Options: leave dormant, or later point this name at the existing DO staging (`do-staging`) by adding it to that site's `server_name`; the empty site and DB are ready if wanted. DNS can stay at SiteGround until then.

### 3.5 app.distrosku.com (legacy PHP)
Pull (after the source is identified in File Manager): the whole `/home/customer/www/app.distrosku.com/` tree, the DB used by it (`mysqldump`), and its config file (DB credentials will be re-entered, not copied into this repo). Check PHP version needs (server is PHP 8.2). Server: copy into `public_html`, import into `dsku_app`, point its config at `dsku_app`, rehearse with `--resolve`. If nobody uses it, archive the files and retire instead of moving.

### 3.6 mail., ftp., autodiscover., autoconfig. and MX/SPF/DKIM/DMARC
No pull and no change. When flipping the web A records keep these records exactly as they are (mail.* stay A 35.212.119.29, MX unchanged, SPF/DKIM/DMARC unchanged). Apex SPF includes `+a`: once the apex A points at DO, mail from SiteGround is still covered by the dnssmarthost include, but any mail sent from the DO IP (currently blocked) would need adding to SPF first.

## 4. DNS plan (when approved; not done)
- T-7: lower TTL to 300 on the A records that will move: `@`, `www`, `vegatest1`, `dba`, `www.dba`, `app`, `www.app`, and `staging` if moved. Do not touch MX/TXT/mail/ftp/autodiscover/autoconfig.
- Cutover per host: change that host's A to `165.245.139.132` (record the old value `35.212.119.29`; rollback = set it back).
- Suggested order: vegatest1 (own window) -> dba -> apex/www -> app -> staging.

## 5. Certificate plan
- Plan A (default): per host `distrosku-site cert <host>` (HTTP-01) immediately after the A record moves; with TTL 300 the browser sees the self-signed placeholder for a few minutes at most. For vegatest1, do this inside the freeze window before telling staff to resume.
- Plan B (no placeholder window): pre-issue with a manual DNS-01 challenge by adding `_acme-challenge.<host>` TXT records at SiteGround before the flip (additive, reversible). Wildcards would not auto-renew, so Plan A per-host certs are preferred.
- certbot's existing timer renews them (`certbot.timer`); the Lets Encrypt cert for `165-245-139-132.sslip.io` is separate and unchanged.

## 6. Risks / notes
- APP_KEY differs between servers (ERP and DBA): see 3.1 and 3.2.
- SiteGround inode limit and one-rollback-folder rules no longer apply on DO; keep the SiteGround copies suspended and intact until the rollback deadline (suggest 48 h per host).
- The apex site is the only host with no known dynamic backend; finish the File Manager read-only check before the cutover rehearsal.
- php-fpm pools run as `www-data` like the existing `distrosku` pool (isolation is by directory and database user, not by Unix user). Splitting users per site is possible later if wanted.
- Do not touch the SiteGround main `laravel` folder, `wp.mymediaworx.com`, or any `reserveworx*` host.

## 7. Phase 2 rehearsal results (2026-10-27 late evening ET; server clock is UTC)

All sites: gate on (401 without credentials), `X-Robots-Tag: noindex, nofollow`, placeholder self-signed cert, `.env` 403, mail logged not sent. No DNS, SiteGround or billable DigitalOcean change. Data came from phpMyAdmin dumps and zips kept locally at mode 600 (`jamison/sg-dumps`); every server-side copy was removed after import and verification. Checks are local `curl --resolve host:443:127.0.0.1` with the gate credentials.

| Host | Deployed | Data restored | Local checks |
|---|---|---|---|
| `vegatest1.distrosku.com` | Vega ERP tag **v0.20.18**, `composer install --no-dev`, new `APP_KEY`, `ERP_INSTANCE`/`ERP_HOME_STATE`/`ERP_COMPANY` carried, `migrate --force` (no-op), `optimize`. Supervisor `distrosku-vegatest1-queue` (www-data) running; www-data cron `schedule:run` added. **Dunning off**, GHL/marketing off (settings blank), `MAIL_MAILER=log`. One manual `erp:backup` verified. | `dsku_vegatest1` 110 tables; `storage/app` public+private (old backups skipped) | `/admin/login` 200, `/up` 200, `/admin` 302, `/.env` 403, no 5xx; `php artisan about` clean |
| `dba.distrosku.com` | distrosku-dba **0.1.1**, new `APP_KEY`, `MAIL_MAILER=log`, `setup-*.php` removed, `migrate` no-op, `optimize` + `filament:optimize`. **No cron yet** (a second poller would double-alert while SiteGround DBA still runs). | `dsku_dba` 16 tables, all rows equal to the dump | `/login` 200, `/up` 200, `/` 302, `/admin` 404, `/.env` 403, `/setup.php` 404; sign-in page renders |
| `distrosku.com`, `www` | Static `index.html` only | `dsku_main` imported (stock Laravel skeleton: users 1, sessions 116, migrations 3), **nothing serves from it** | `/` 200, `/index.php` 404, `/.env` 403 |
| `app.distrosku.com`, `www.app` | Legacy PHP tree unpacked to `public_html`; `config.php` pointed at `dsku_app`, `display_errors` off; original config kept root-only at `/root/orig-config-backups/`; `schema.sql`, `add_public_token.sql`, `php_errorlog`, `README.md` not deployed; nginx now denies `/includes/` and `/config.php`. All files lint clean on PHP 8.2.35; code needs no Composer vendor tree (no `vendor/` references) | `dsku_app` 7 tables, 27 rows equal to dump; uploads copied | `/login.php` 200, `/master.php` 200, `/index.php` `/dashboard.php` `/catalog.php` 302 to login, `/public_sku.php` 200, `/config.php` and `/includes/db.php` 403, `/schema.sql` 403, uploads served |
| `staging.distrosku.com` | `index.php` placeholder ("No application is deployed here yet") | none | `/` 200, `/.env` 403 |

`do-staging` (`165-245-139-132.sslip.io`), its queue worker and cron were not touched and still respond (gated 401, 200 with credentials).

### 7.1 Row counts vs the dumps
vegatest1: every table matches except three that the app itself wrote after import (`activity_logs` +8, `rules_syncs` +1, `sessions` +4). Key tables: customers 909, products 8016, users 5, vendors 228, customer_licenses 496, price_rules 2282, product_barcodes 8710, ship_tos 914, shared_products 7885; sales orders, inventories, stock movements, journals, POs are 0 in the source (master data only). dba: all 16 tables equal (instances 1, users 1, instance_backups 6, audit_logs 12, instance_checks 1303). app: tenants 1, skus 4, products 4, users 3, departments 7. main: users 1, sessions 116.

### 7.2 What the new `APP_KEY` broke, and how it was handled
Everything below is data that was encrypted with the SiteGround key. The ciphertext is intact in the dumps, so carrying the source `APP_KEY` into `.env` before cutover (and re-importing the two tables) restores all of it without any re-entry.
- **vegatest1 `settings` table:** every non-null value is cast `encrypted`. 42 of 44 rows were undecryptable and were set to NULL (reads fall back to defaults; writes of an undecryptable row throw "The MAC is invalid", which broke `erp:backup` until blanked). Two rows are fresh (`install_id`, `dba_last_backup`). Keys to re-enter if the source key is not carried: `authnet_client_key`, `authnet_login_id`, `authnet_transaction_key`; `dunning_levels`; `ky_filer_{name,fein,license,address,city,state,zip}` and `ky_rep_{first,last,title,email,phone}` (KY filer/representative details); `mkt_{enabled,notify_enabled,token,location_id,app_url,webhook_url,cf_account,cf_resale,cf_state_tax}` (GHL marketing, stays off); `rules_hub_url`, `rules_hub_public_key`, `dba_secret`, `dba_rules_version`, `dba_rules_applied_at`; and the tuning values `coa_expiry_warn_days`, `count_auto_approve_pct`, `count_auto_approve_value`, `lot_expiry_alert_days`, `over_receipt_pct`, `pos_hold_days`, `reorder_cover_days`, `reorder_default_lead_days`, `reorder_velocity_days`, `msa_directory_strict`, `pact_interstate_enabled`, `phase194_shiptos_flipped`. Company Settings screens show defaults until re-entered. Do **not** turn dunning or marketing on before checking these.
- **dba:** only `instances.secret` for the one instance (vegatest1) was encrypted (no 2FA is set on the DBA user). It was replaced with a fresh random secret under the new key, so the DBA can no longer sign to vegatest1 (poll reports "bad signature") until it is re-linked: DBA > Instances > vegatest1 > *New link secret*, then paste it into the ERP's link secret (`ERP_DBA_SECRET`). With the source key carried, no re-link is needed.
- **app:** none (plain PHP, no APP_KEY). Its `config.php` also holds a plaintext master-admin password, carried unchanged from the source.

### 7.3 Open items and gaps found
- `distrosku.com` `index.html` references `logo.jpg`, which is not in the apex zip (404 on DO). Get the file from SiteGround File Manager before cutover (read-only).
- `robots.txt` noted in section 1 is also not in the apex zip; the rehearsal site has none (the gate adds noindex for now).
- The apex zip's `storage` entry was a dangling symlink and was skipped.
- DBA settings that live in the SiteGround `.env` and were not carried (alert recipients, owner e-mail, GitHub repo) are blank on DO; re-enter before cutover. DBA mail is `log`.
- `MAIL_MAILER=log` on vegatest1 and dba; the SMTP relay plan and SPF change are still open.
- ERP features that need a source-side value (Spaces bucket, SMTP, Authorize.net) are not configured.
- The gate password appeared in agent tool output during this work; rotate `/etc/nginx/distrosku.htpasswd` before the cutover rehearsal is shared.
- A verified manual `erp:backup` exists at `/var/backups/erp/vegatest1/` (contains customer data, mode 600, www-data); the nightly 01:30 backup will add more there. Delete the manual one when no longer needed.

### 7.4 Remaining before DNS cutover (nothing here is done)
1. Michael's go-ahead and a window (vegatest1 first, own freeze).
2. Decide `APP_KEY`: carry the SiteGround keys (ERP and DBA; obtained without printing) or accept the re-entry lists above.
3. Fresh backup at freeze; re-run import and counts; refresh uploads.
4. T-7: TTL 300 on `@`, `www`, `vegatest1`, `dba`, `www.dba`, `app`, `www.app`, `staging`.
5. Per host: gate off, `distrosku-site cert <host>`, A to `165.245.139.132`, verify, keep SiteGround intact for rollback (48 h).
6. DBA: add `schedule:run` cron on DO only after the flip; stop the SiteGround DBA cron at the same time; re-link vegatest1.
7. Enable dunning/marketing/mail only on Michael's explicit approval.
8. Confirm with Michael how app.distrosku.com is used (logins exist: 3 users, 1 tenant) and that the apex `logo.jpg` is fetched.
