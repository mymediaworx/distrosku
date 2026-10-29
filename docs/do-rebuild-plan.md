# DistroSKU on DigitalOcean: rebuild plan (research only)

Prepared 2026-10-27 (America/New_York). Status: DRAFT for COS/Michael approval. **Nothing has been created, changed or deployed.**
All DigitalOcean calls made while researching were read-only (list droplets/sizes/regions/DB options/apps/firewalls/account/backup policy). No credentials appear in this file.

Decision from Michael (2026-10-27, 8:08 PM ET): distrosku gets **its own dedicated server**. Sharing reserveworx-prod-1 is dropped. App Platform is not the primary path.

---

## 1. Recommendation (one line)

**One dedicated Droplet in atl1: `s5-2vcpu-4gb-50gb` (2 vCPU, 4 GiB RAM, 50 GiB disk, $40.67/mo), MariaDB 11.8 on the same box, daily Droplet backups, nightly DB/ERP dumps to Spaces.**
Total about **$57.87/mo** (details in section 3). One size up, if the load needs it: `s5-4vcpu-8gb-50gb` at about **$104.11/mo**.
Managed MySQL stays an **optional add-on only** (+$30.45/mo minimum). It is not recommended, for the reasons in section 4.

## 2. What was checked (read-only)

### DigitalOcean account (API, today)
| Item | Finding |
|---|---|
| Account | active, email verified, Droplet limit 10 (1 used) |
| Droplets | exactly one: `reserveworx-prod-1` |
| App Platform apps | none |
| Managed DB clusters | none |
| DO-hosted domains (DNS) | none. DNS for distrosku.com is not on DigitalOcean, so a temp hostname needs an A record at the current DNS host |
| atl1 | Available, with features backups, ipv6, metadata, storage. Ubuntu 24.04 image available in atl1. Managed MySQL (8.4) and Spaces are offered in atl1 |
| atl1 Droplet sizes | **only the `s5-*` (v5 shared) line is offered in atl1.** The cheaper basic slugs (for example `s-2vcpu-4gb` at $24) are not available there. Prices below are the real atl1 prices |

### reserveworx-prod-1 (for context only, not used by this plan)
- Slug `s5-2vcpu-4gb-50gb`, atl1, Ubuntu 24.04, public IP 129.212.180.114, VPC private networking on, monitoring agent enabled, tags reserveworx/prod.
- **Created 2026-10-28 00:04 UTC (8:04 PM ET today). It is a brand-new box**, so any load number would be meaningless.
- Daily backups are enabled (7-day retention, 4-hour window). This confirms daily-backup pricing in section 3.
- Cloud firewall `reserveworx-prod-1-fw`: inbound 22, 80 and 443 open to the whole internet; all outbound allowed.
- nginx answers on 80/443 (app.reserveworx.com returns HTTP/2 from nginx).
- Current CPU/RAM load and disk use: **not obtainable.** The Insights (Prometheus) endpoint returned an empty result for the one query that worked and timed out on the metric-name listing, and the API does not expose disk usage. Provisioned disk is 50 GiB. Verdict: no usable data, and moot now that distrosku gets its own server.
- Isolation concern that drove Michael's call: it is another product's production box. A shared box means a shared PHP-FPM/OS/disk, MariaDB restarts and upgrades that hit both, one SSH key set, and one firewall.

### SSH reachability from the box (Grok's box, not Michael's computer)
- `nc` is not installed here, so I used a bash TCP probe (equivalent to `nc -zv -w5`), read-only.
- **reserveworx-prod-1 (129.212.180.114) port 22: OPEN.** It returned the banner `SSH-2.0-OpenSSH_9.6p1 Ubuntu` (OpenSSH 9.6 is Ubuntu 24.04). Ports 80 and 443: open.
- github.com port 22: **open** from the box.
- Conclusion: outbound SSH from the box to a DO Droplet on 22 works, and so does SSH to GitHub. The earlier block (SiteGround 18765, GitHub 22) was on Michael's computer's network, not on the box. **The box can do the whole droplet build over SSH, once it has an SSH key registered on the new Droplet** (see section 7 for who adds the key).

## 3. Monthly cost (sizes and prices from the DO API for atl1; backup, Spaces, DB, App Platform prices from the official DO pricing pages checked today)

### Recommended: `s5-2vcpu-4gb-50gb`
| Line | Monthly |
|---|---|
| Droplet `s5-2vcpu-4gb-50gb` (2 vCPU / 4 GiB / 50 GiB, 4 TB transfer) | $40.67 |
| Droplet backups, **daily**, 7 copies kept (30% of Droplet price) | $12.20 |
| (or backups **weekly**, 20%) | ($8.13) |
| Spaces subscription for nightly dumps and uploads (250 GiB + 1 TiB egress included; ATL1 Spaces to ATL1 Droplet egress is free) | $5.00 |
| Cloud firewall, monitoring, reserved IP (while attached), IPv6 | $0 |
| **Total with daily backups** | **$57.87** |
| Total with weekly backups | $53.80 |
| Total with no Droplet backups (not advised; Spaces dumps only) | $45.67 |

### Alternative, one size up: `s5-4vcpu-8gb-50gb`
| Line | Monthly |
|---|---|
| Droplet (4 vCPU / 8 GiB / 50 GiB, 5 TB transfer) | $76.24 |
| Backups daily (30%) | $22.87 |
| Spaces | $5.00 |
| **Total with daily backups** | **$104.11** |
| Total with weekly backups ($15.25) | $96.49 |

Other atl1 slugs, if wanted: `s5-2vcpu-4gb-80gb` $43.72 (same CPU/RAM, 80 GiB disk); `s5-2vcpu-6gb-50gb` $46.68 (cheap RAM step); `s5-2vcpu-2gb-50gb` $34.65 (too tight, not advised).
Why 4 GiB is the starting size: PHP-FPM for admin/POS/portal, a Supervisor queue worker, MariaDB with about a 1 GiB InnoDB buffer pool, and room for `composer`/zip upgrades. The data is small (the app's storage dir is about 43 MB, about 70k inodes in total on SiteGround). Bigger is a one-click resize later (CPU/RAM-only resizes can be reversed; disk resizes cannot, so do not enlarge the disk until needed).

### Optional add-on only: Managed MySQL
| Plan | Monthly |
|---|---|
| `db-s-1vcpu-1gb` (1 GiB, 10 GiB disk) | $15.15 (too small for this app) |
| **`db-s-1vcpu-2gb`** (2 GiB, 30 GiB) | **$30.45** (smallest sensible) |
| `db-s-2vcpu-4gb` (4 GiB, 60 GiB) | $60.90 |
| Extra disk | $0.215/GiB/mo |
Engine offered in atl1: **MySQL 8.4 only** (standard layouts: 1, 2 or 3 nodes). With the add-on, the Droplet total becomes about $88.32/mo (recommended Droplet + `db-s-1vcpu-2gb`). It would let the Droplet drop to a smaller size, but that is not worth it.

### Other options priced, for the record
- Load balancer: **not needed** (one server). Would be $12/mo each. A free reserved IP is enough.
- App Platform (not recommended): `apps-s-2vcpu-4gb` $50/mo for the web service plus a worker component ($25-$50) plus a managed DB ($30.45+) = roughly $105-$130/mo, and it needs code changes (section 5).

## 4. Options compared

| | (a) Share reserveworx-prod-1 | **(b) Own Droplet, MariaDB on the box (RECOMMENDED)** | (c) Own Droplet + Managed MySQL | (d) App Platform |
|---|---|---|---|---|
| Status | **Dropped by Michael** | Primary | Optional add-on | Not primary |
| Monthly | $0 extra, but see risk | ~$57.87 | ~$88.32 | ~$105-$130 |
| Isolation / blast radius | Poor: another product's prod, shared OS, FPM, DB and disk | Full | Full, DB isolated too | Full |
| Database engine | MariaDB 11.8, same as CI and SiteGround | **MariaDB 11.8, identical to production today** | **MySQL 8.4, not MariaDB. Needs a full test pass first** | MySQL 8.4 (managed) |
| Ops burden | Highest (two products' patching on one box) | Moderate: patching, DB tuning, backups are ours | Lower for DB | Lowest |
| Fit with existing deploy/upgrade tooling | Poor | **Native (same folder layout)** | Native | Poor, needs rework |

### MariaDB vs MySQL 8.4 (why the managed DB is not recommended)
- Production and CI use MariaDB: Laravel connection `mariadb`, CI image `mariadb:11.8`, utf8mb4_unicode_ci.
- I read the app for engine-specific SQL. Raw SQL is limited to portable standard SQL (`COALESCE`, `ROUND`, `LOWER`, `SUM`, `CASE`, `... IS NULL` ordering, `GROUP BY`) and one migration branch guarded for `mysql`/`mariadb`. No MariaDB-only functions, no JSON-column functions, no sequences, no `RETURNING`, no `INSERT IGNORE`/`ON DUPLICATE` raw SQL were found. So MySQL 8.4 is **likely** compatible, but **it is not tested**: CI only has SQLite and MariaDB jobs.
- Gating work if the managed DB is ever wanted: add a third CI job (`mysql:8.4`, `DB_CONNECTION=mysql`), get it green, and set `sql_mode` and the connection to match. Also note `mysqldump` flags (`--single-transaction --quick --routines --no-tablespaces`) in `BackupService::mysqldump` work with MySQL clients, and the main ERP backup is a JSON-lines zip that is engine-neutral.
- Other managed-DB facts to weigh: private-network latency on every query for a chatty ERP; SSL by default; no local-socket access; DB version upgrades are the provider's schedule.

### App Platform (assessment)
- **Scheduler:** the app is built for a shared host. `schedule:run` must run every minute and also drains the queue (`queue:work --stop-when-empty` is scheduled every minute). App Platform has no cron for a web service; it would need a separate "Worker" (always-on, billed) running `schedule:work`, or Jobs on a schedule (minimum granularity is a poor fit for every-minute).
- **Queue workers:** supported as Worker components, but a continuous `queue:work` plus `schedule:work` means two paid workers ($25-$50 each).
- **File storage:** the container filesystem is **ephemeral**. The app writes delivery photos and signatures, COA PDFs, MSA/import files, license documents, product images and the company logo to `storage/app/private` and `storage/app/public` via the `local`/`public` disks (about 43 MB today and growing). These would need to move to Spaces (S3 disk). The repo has no `league/flysystem-aws-s3-v3` package and several places use `Storage::disk('local')->path()` (file path access) that does not work on S3. That is a code change.
- **Self-deploy, DBA manager and backups:** the signed-zip `deploy.php`, instance manager and `erp:backup` writing to a local path all assume a persistent filesystem.
- **Verdict:** not suitable without a code and deploy-pipeline rework. Revisit only if the app is made stateless.

## 5. Target stack on the Droplet

Single Ubuntu 24.04 LTS Droplet, hostname `distrosku-prod-1` (name is a suggestion).

| Layer | Choice |
|---|---|
| OS | Ubuntu 24.04 LTS, unattended-upgrades on, 2 GiB swap file |
| Web | nginx; one server block per instance (`distrosku.com`, `dba.distrosku.com`, `vegatest1`, `staging`); HTTPS with certbot (nginx plugin, auto-renew timer) |
| PHP | php8.2-fpm (Ubuntu 24.04 ships 8.3, so use the ondrej/php PPA, or Ubuntu's `php8.3` only if tests pass; **composer platform is pinned to 8.2.0, so stay on 8.2**). Extensions: bcmath, intl, gd, zip, mbstring, mysql, curl, xml, opcache. `proc_open`/`shell_exec` must stay enabled (the deploy and backup code use them). One FPM pool per instance, `pm = ondemand` for the test instances |
| DB | MariaDB 11.8 from the official MariaDB repo (same major as CI), `utf8mb4`/`utf8mb4_unicode_ci`, `innodb_buffer_pool_size` about 1 GiB on 4 GiB RAM (about 3 GiB on the 8 GiB size), bind to localhost only, one DB + one user per instance |
| Cache/session/queue | Keep today's `database` drivers (works as is). Redis is **optional** later (same box, localhost only), not needed at Vega's volume |
| Queue + scheduler | Supervisor runs `queue:work --sleep=3 --tries=3 --max-time=3600` per instance. `* * * * * cd <site>/laravel && php artisan schedule:run` in the `www-data` crontab. The scheduler already queues `queue:work --stop-when-empty` every minute, so a Supervisor worker is a speed-up and the cron line is the safety net. The nightly jobs already in the schedule keep working: `erp:backup` 01:30, `rules:sync` 02:30, `dunning:send` 07:00, etc. |
| Time zone | `APP_TIMEZONE=America/New_York` (already in `.env.example`); server in UTC |
| Security | ufw (22, 80, 443 only), a DO Cloud Firewall with the same rules (SSH limited to known IPs if possible), fail2ban (sshd and nginx-http-auth), key-only SSH, no root login, MariaDB not exposed |
| Logs | The app already rotates daily (`LOG_STACK=daily`, `LOG_DAILY_DAYS=14`); add logrotate for nginx and supervisor logs |
| Mail | Dunning mailbox is SMTP 465 to SiteGround, settings stored in the ERP (Company Settings, Email tab). DO blocks outbound port 25 on many accounts, but 465/587 normally work. Verify with the "Send test email" button on the temp site |
| Backups | (1) DO daily Droplet backup (7 copies); (2) `erp:backup --trigger=nightly` at 01:30 writes to `ERP_BACKUP_PATH` (set to `/var/backups/erp/<instance>`, outside every web root); (3) a 02:15 job runs `mariadb-dump --single-transaction --routines` and syncs the dump + latest ERP backup zip + `storage/app` to a private Spaces bucket with `rclone` or `s3cmd` (keep 30 daily, 12 monthly; set a Spaces lifecycle rule); (4) a quarterly restore drill |

### Deploy and rollback
Keep the existing, tested mechanism so nothing about releases changes:
- Same folder layout as SiteGround: `/var/www/<site>/{laravel,public_html}` (`bootstrap/app.php` already picks the sibling `public_html`), owned by the deploy user and writable by `www-data`.
- `bash deploy.sh <upgrade.zip> https://<site>` talks to the signed `public_html/deploy.php` (HMAC + Ed25519), swaps `laravel/` for the new code, runs migrations and the health check, and **keeps exactly one rollback copy (`laravel-prev`)**. `bash deploy.sh rollback <url>` restores it. This already satisfies "releases/ with only one rollback kept". There is no need to introduce a `releases/` symlink scheme now.
- Optional later: a GitHub Actions workflow that builds the upgrade zip and calls the same endpoint, with the Ed25519 private key and HMAC secret as GitHub Secrets (never in the repo). CI today only reports test results and releases stay manual, so this is a future step.
- Document the `.env`, `storage/` and `.deploy-secret` as the only per-instance state; they are carried across upgrades by the deploy script.
- The deploy and upgrade PHP must be allowed to call `rename`, `exec` and `curl` as the FPM user; confirm in the staging pass.

## 6. Migration steps (build and verify only. Cutover is a plan, NOT executed)

### Phase A: build on a temporary hostname (no production impact)
1. **COS/Michael approve and create the Droplet** (Section 7). Region atl1, image `ubuntu-24-04-x64`, slug `s5-2vcpu-4gb-50gb`, daily backups on, IPv6 on, monitoring on, SSH key for the box, cloud firewall (22/80/443), reserved IP optional.
2. **Temp hostname.** Option 1: `do-staging.distrosku.com`, one **A record** to the Droplet's IP, added by COS or Michael wherever DNS for distrosku.com lives (it is not on DO). Option 2, no DNS change at all: `<ip-with-dashes>.sslip.io` (for example `203-0-113-10.sslip.io`), which certbot can issue a certificate for. Use sslip.io first for speed, then the real name if needed.
3. Harden and install: users, ufw, fail2ban, nginx, PHP 8.2-FPM, MariaDB 11.8, Supervisor, certbot, rclone.
4. Create the temp site `/var/www/do-staging/{laravel,public_html}`, an empty database + user, and the nginx and FPM pool. Deploy the **same upgrade zip that vegatest1 runs (0.20.18)** (the repo already has `vega-erp-phase20.18-upgrade.zip`; the first install uses the setup-token flow from `README_DEPLOY.md`, or unpack the zip and run `php artisan migrate --force` over the restored DB).
5. **Restore a vegatest1 copy** with the ERP's own backup: on vegatest1 take a backup with the ERP backup tool (Instance manager / `erp:backup --trigger=manual`), download the zip, then restore it on the temp site with `BackupService` (restore keeps the instance's own secrets listed in `KEEP_SETTINGS`). The backup is JSON-lines, so it also proves the MariaDB-to-MariaDB path. Do this over HTTPS or SSH from the box; **no database credentials are ever printed or pasted into chat**.
6. Set temp `.env` values (APP_URL = the temp host, DB, mail left on `log` until tested). **Do not link the temp site to the live instance manager (do not reuse `ERP_DBA_SECRET`)** so the manager does not see two copies of the same instance.
7. Verify (checklist): admin login; Dashboard tiles and reports; POS page; B2B portal login; Trace/lots screen; create and save a test sales order and pick; PDF statement renders (dompdf); uploads and downloads (COA, logo, delivery photo); `php artisan schedule:run` works and the Supervisor queue worker processes a job; Send test email; `erp:backup` writes and the Spaces sync uploads; `php artisan test` smoke on the box if desired; page-load timing; `deploy.sh status` and a deliberate rollback drill.
8. Load check: watch CPU/RAM and `iostat` for a day with real use. If sustained CPU > 60% or swapping, resize to `s5-4vcpu-8gb-50gb` (about 2 minutes of downtime).
9. Repeat the build as a second site on the same Droplet for `dba.distrosku.com`, and (optionally) for vegatest1/staging, **after** the main site is proven.

### Phase B: cutover plan (for later, not executed. Needs Michael's go-ahead and a quiet window)
1. T-7 days: lower the TTL on the distrosku.com records to 300 s.
2. Freeze: put the SiteGround site in maintenance mode (or suspend it from the instance manager) at the start of the window. Ask staff to stop entering orders (Vega's off-hours).
3. Take a final ERP backup on SiteGround, copy it (and `storage/app`) to the Droplet, restore, then run the migrations the Droplet needs.
4. Run the verification checklist on the temp hostname using the final data.
5. Point DNS: change the A record(s) for distrosku.com (and the dba host) to the Droplet's reserved IP. Issue the certificate with certbot (if DNS is not live yet, use the DNS or HTTP challenge on the temp name beforehand and switch the `server_name`).
6. Re-link the instance manager with the production secret, switch the mail setting to SMTP, turn on auto-email dunning only after a test.
7. Watch for 24 hours: logs, queue, nightly backup, Spaces sync, SMTP.
8. **Rollback:** point DNS back to SiteGround (kept untouched and intact, in maintenance mode). Orders entered on the Droplet after cutover must be re-keyed or migrated back by backup, so decide the rollback deadline before the cutover.
9. Decommission SiteGround only after at least two weeks and one restore drill from Spaces.

## 7. What COS/Michael must approve or create

Approvals (billable, so nothing was created by me):
1. Create **one** Droplet: atl1, `s5-2vcpu-4gb-50gb`, Ubuntu 24.04, daily backups enabled, IPv6, monitoring, tag `distrosku`. About $52.87/mo for the Droplet and its backups.
2. Create a **Spaces** subscription and a private bucket (ATL1) for backups, plus a **Spaces access key scoped to that bucket**. The key goes to the Droplet's `rclone` config only, never in chat or the repo. $5/mo.
3. Add the **box's SSH public key** to the Droplet (at creation, through the DO SSH key list) so the build can run from here. The box can reach port 22. I will print only the public key. Never any private key.
4. A cloud firewall allowing 22/80/443 (restrict 22 to Michael's/COS's IPs if you can).
5. DNS: one **A record** for `do-staging.distrosku.com` to the Droplet IP, or approve using sslip.io instead (no DNS work).
6. Decide whether `dba.distrosku.com`, vegatest1 and staging move to the same Droplet (they fit at the starting size) or stay on SiteGround for now.
7. Optional: managed MySQL ($30.45/mo and up) only after a MySQL 8.4 CI job passes. Not recommended now.

Guardrails kept: no DNS cutover, nothing published or replaced, SiteGround original untouched, no credentials printed.

## 8. Risks and open questions
- **Capacity numbers are an estimate.** No live CPU/RAM figures exist for any DO box (the one existing Droplet is minutes old and the metrics endpoint gave nothing). The first day on the temp site will confirm or change the size.
- **Backups:** DO Droplet backups are whole-disk copies taken while MariaDB runs (crash-consistent). The nightly dump to Spaces is the real database protection. Both are in the plan.
- **Single server = single point of failure.** Mitigation: daily backups, a documented restore, a Spaces copy, and a resize path. A second Droplet or a managed DB with standby nodes is the next tier up if uptime requirements grow.
- **PHP 8.2 on Ubuntu 24.04** needs a third-party PPA (Ubuntu's own is 8.3). Decide before the build; the plan assumes the PPA.
- **MariaDB 11.8 repo packages** for Ubuntu 24.04 (noble) are from MariaDB's own apt repo.
- **Droplet outbound mail** to SiteGround SMTP (465) is expected to work; confirmed only by the test email.
- **Deploy keys.** Confirm who holds the Ed25519 private key and HMAC secret for `deploy.sh` before the first deploy to the Droplet (they are in `secrets/`, never committed).
- Sizes and prices were read from the DO API and the official pricing pages today (2026-10-27) and can change; re-check right before creating anything.

---

## 9. Server build notes: distrosku-prod-1 (built 2026-10-27, America/New_York)

**Status: STAGING build on a temporary hostname. Not published, no DNS change, SiteGround untouched.** No secrets are in this file.

| Item | Value |
|---|---|
| Droplet | `distrosku-prod-1`, ID 613331562, atl1, `s5-2vcpu-4gb-50gb`, IPv4 165.245.139.132, Ubuntu 24.04.5 |
| URL | https://165-245-139-132.sslip.io (Let's Encrypt cert issued via certbot nginx plugin, auto-renew timer; expires 2027-01-25). Note: the cert is in public CT logs, so the hostname is discoverable. HTTP 301-redirects to HTTPS. |
| Access gate | nginx basic auth in front of the whole site (except `/robots.txt`, which disallows all) + `X-Robots-Tag: noindex, nofollow, noarchive`. Credentials: `/workspace/secrets-sg/do-staging.auth` on the Grok box (chmod 600). ERP admin login for the seeded demo data: `/workspace/secrets-sg/do-staging.admin` (chmod 600). |
| Versions | nginx 1.24.0, PHP 8.2.35 (ondrej PPA, FPM + CLI), MariaDB 11.8.9 (official repo, bound to 127.0.0.1), Composer 2.10.3, Laravel 12.70.0, app 0.20.18 |
| SSH | `ssh distrosku-do` (box `~/.ssh/config`, key `~/.ssh/siteground_distrosku`, public half comment `brandworx-dev-box`). Users: `root` (key only, `PermitRootLogin prohibit-password`) and `deploy` (sudo NOPASSWD, same key). Hardened 2026-10-28 (see 9.1): password and keyboard-interactive auth off, `MaxAuthTries 3`, no TCP/agent/X11 forwarding. |
| App path | `/var/www/do-staging/{laravel,public_html}` (owner `deploy:www-data`; `storage/` and `bootstrap/cache/` group-writable). Installed by unzipping `vega-erp-phase20.18-upgrade.zip`; the one-shot `upgrade-*.php` was removed. `public_html/storage` symlink -> `laravel/storage/app/public`. |
| `.env` | Server only (`laravel/.env`, 640). `APP_ENV=staging`, `APP_DEBUG=false`, `LOG_DAILY_DAYS=14`, `DB_CONNECTION=mariadb` on 127.0.0.1, `MAIL_MAILER=log`, `ERP_INSTANCE=do-staging`, `ERP_BACKUP_PATH=/var/backups/erp/do-staging`, `ERP_DBA_SECRET` empty (not linked to the instance manager). `APP_KEY` is NEW (see DB section). |
| PHP-FPM | dedicated pool `distrosku` (`pm=ondemand`, socket `/run/php/php8.2-fpm-distrosku.sock`), default `www` pool disabled. `/etc/php/8.2/fpm/conf.d/99-distrosku.ini` (512M, 32M uploads, `expose_php` off, session cookie flags). `proc_open`/`exec` not disabled (the ERP needs them). |
| nginx | `/etc/nginx/sites-available/do-staging`; basic-auth file `/etc/nginx/distrosku.htpasswd`. Only `/index.php` may execute PHP (any other `.php` returns 404). Catch-all default server drops unknown hostnames. |
| Queue | Supervisor program `distrosku-queue` (`queue:work --sleep=3 --tries=3 --max-time=3600`, user `www-data`), log `/var/log/supervisor/distrosku-queue.log`. |
| Scheduler | `www-data` crontab: `* * * * * cd /var/www/do-staging/laravel && php artisan schedule:run`. |
| Security | ufw: in 22/80/443; **out DENY 25/465/587/2525** (so staging cannot send SMTP even if configured). fail2ban jails `sshd`, `nginx-http-auth`. unattended-upgrades on. Server clock UTC. 2 GiB swapfile (`vm.swappiness=10`). DO cloud firewall `distrosku-prod-1-fw` (22/80/443) is separate, set by COS. |
| Mail guard | `MAIL_MAILER=log`. `/usr/local/sbin/staging-mail-guard` (run every minute from `/etc/cron.d/staging-mail-guard`) deletes saved `mail_host`, `mail_username`, `mail_password` and `dunning_auto_email` from the `settings` table, because a saved host overrides `.env` (see `App\Mailing\MailConfig`). **Remove this cron and the ufw out-denies only at production cutover.** |
| DB backups | `/usr/local/sbin/distrosku-dbdump` nightly 02:15 UTC (`/etc/cron.d/distrosku-dbdump`) -> `/var/backups/distrosku/distrosku_staging-*.sql.gz` (root, 0600; `single-transaction`, gzip, 14-day retention) plus `distrosku_storage-*.tar.gz` of `storage/app`, local only (no Spaces bucket yet). Weekly restore test: `/usr/local/bin/distrosku-restore-test` (Sun 03:30 UTC, log `/var/log/distrosku-restore-test.log`). The ERP's own nightly `erp:backup` (01:30 ET) writes to `/var/backups/erp/do-staging`. |
| Logrotate | `/etc/logrotate.d/distrosku` (queue log, FPM log, app logs) and `/etc/logrotate.d/distrosku-backup` (backup and restore-test logs). |
| Deploy | `deploy.php` is present in `public_html` (signed, fails closed without `laravel/.deploy-secret`) but **nginx returns 404 for it by default** since the 2026-10-28 hardening. Run `distrosku-deploy-endpoint on` before an upgrade and `off` after. `bash deploy.sh <zip> https://165-245-139-132.sslip.io` needs the box's deploy secrets and the basic-auth header; not yet exercised on this host. |

### Database restore status
- **No vegatest1 data restored.** (`php artisan erp:restore <zip> --force` is the CLI restore; see `docs/do-cutover-runbook.md` section 5.) No recent vegatest1 backup exists on the Grok box. `/workspace/jamison/loadpkg/*.sql.gz` is the Jamison import package, not a vegatest1 backup. The vegatest1 backup path today is the signed `dba-api` (`/dba-api/backup`, needs the instance's DBA secret) or `php artisan erp:backup` on SiteGround; this app has no browser backups page. SiteGround SSH (`sg-distrosku`, port 18765) was closed by the host proxy and `https://vegatest1.distrosku.com` answered with a `sg-captcha: challenge`, so neither could be reached from the box.
- The app boots on an **empty database + seed**: migrations (through `2026_11_28_000001_phase2018_dunning_email`), `CoreSeeder`, `DemoSeeder`, and one `brandworx_admin` user (4 users, 10 products, 4 customers).
- **APP_KEY:** a new key was generated. A restored backup that contains encrypted settings (the SMTP password in Company Settings > Email, saved by the Laravel `encrypted` cast) cannot be decrypted with this key. After restore, re-enter such values (the mail guard removes SMTP settings anyway). To keep vegatest1's key instead, set `APP_KEY` in `laravel/.env` before restoring, without printing it.
- To restore later: copy the `erp:backup` zip (`vegatest1-YYYYMMDD-HHMMSS-*.zip`) to the server and restore with `App\Dba\BackupService` (the instance-link settings in `KEEP_SETTINGS` are kept), then `php artisan config:clear`, confirm `staging-mail-guard` ran, and re-check Company Settings > Email shows no host and Auto-email dunning is off.

### 9.1 Hardening pass (2026-10-27 night, America/New_York) and cutover runbook
Audit and fixes on the staging build, with the originals saved root-only in `/root/hardening-backup-20261028/`. The step-by-step cutover is in `docs/do-cutover-runbook.md`.
- **SSH:** key-only, `MaxAuthTries 3`, `LoginGraceTime 30`, forwarding off (re-enable `AllowTcpForwarding` only for a DB tunnel). Second authorized key on root/deploy for the ReserveWorx agent was left in place pending an owner decision.
- **Firewall / intrusion:** ufw unchanged (in 22/80/443, out-deny mail ports). fail2ban `nginx-http-auth` jail now reads `/var/log/nginx/error.log` (it was on the journal and never matched).
- **Updates:** unattended security upgrades, no automatic reboot. A kernel update is waiting for a reboot.
- **nginx:** `server_tokens off`, TLS 1.2/1.3, security headers (HSTS 6 months, no preload, no CSP because Filament/Livewire use inline scripts), PHP only via `index.php`, `deploy.php` blocked unless switched on, dotfile and sensitive-extension deny, catch-all 444.
- **PHP / DB:** hardened ini, `request_terminate_timeout=150s`; MariaDB local-only with `local_infile=0`, `skip-symbolic-links`; the app DB user holds no ROUTINE/EVENT/GRANT (a future migration that needs those needs an explicit grant).
- **Laravel:** `APP_DEBUG=false`, config cached (run `php artisan config:cache` after editing `.env`), `storage/app` made writable for `www-data` (it was root-owned).
- **Backups:** nightly dump + `storage/app` tarball with 14-day retention; weekly automatic restore test into scratch DB `distrosku_restoretest` that compares table and key-table counts with the live DB and drops the scratch DB (first run PASS). `erp:backup` verified end-to-end.
- **Not done:** the reboot, off-server (Spaces) backups, CSP, `disable_functions`.

### Verification (2026-10-27 ~8:21 PM ET)
`/admin/login` 200 over HTTPS (401 without the basic-auth header), `/up` 200, `php artisan about` clean (staging, debug off, mail log, mariadb, storage linked), Supervisor worker RUNNING and picking up jobs, `schedule:run` firing every minute from cron, ufw/fail2ban/nginx/php8.2-fpm/mariadb/supervisor all active.
