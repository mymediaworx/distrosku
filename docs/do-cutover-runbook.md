# DistroSKU on DigitalOcean: cutover runbook (SiteGround -> distrosku-prod-1)

Prepared 2026-10-28 (America/New_York) by Brandworx Dev. Status: **DRAFT. Nothing in this runbook has been executed.**
Companion to `docs/do-rebuild-plan.md` (cost, sizing, architecture). Server times below are UTC unless marked ET.

**Rules for whoever runs this**
- No secrets, tokens or passwords go into this file, chat, tickets or commit messages. Credentials stay in `/workspace/secrets-sg/` on the Grok box (chmod 600) or on the server's own `.env`. Never paste an upgrade/setup token or a URL that contains one.
- Nothing here runs without Michael's explicit go-ahead for the specific window. DNS, SiteGround changes, mail enablement and publishing each need their own yes.
- Never touch `wp.mymediaworx.com`, `app.reserveworx.com` / `reserveworx*`, or the live SiteGround `laravel` folder except for the read-only/maintenance steps named in section 4.
- Run every `artisan` command as the web user: `sudo -u www-data php artisan ...` from `/var/www/<site>/laravel`.

## 0. Current state (staging, verified 2026-10-28)

| Item | State |
|---|---|
| Server | `distrosku-prod-1`, Ubuntu 24.04, nginx, PHP 8.2-FPM, MariaDB 11.8 (localhost only), Supervisor queue worker, scheduler cron |
| Staging site | `https://165-245-139-132.sslip.io`, `/var/www/do-staging/{laravel,public_html}`, ERP 0.20.18, **demo data only** |
| Gates still ON | nginx basic auth, `X-Robots-Tag: noindex`, `robots.txt` Disallow, ufw out-deny 25/465/587/2525, `staging-mail-guard` cron (wipes saved SMTP settings every minute), `MAIL_MAILER=log` |
| Hardening done | key-only SSH (MaxAuthTries 3), ufw 22/80/443 only, fail2ban `sshd` + `nginx-http-auth`, unattended security upgrades, nginx security headers, PHP only via `index.php`, `/deploy.php` blocked unless switched on, least-privilege DB user, `local_infile` off |
| Backups on the server | nightly 02:15 UTC `/usr/local/sbin/distrosku-dbdump` -> `/var/backups/distrosku` (DB dump + `storage/app` tarball, 14 days, 0600); ERP's own `erp:backup` 01:30 ET -> `/var/backups/erp/do-staging` |
| Restore test | `/usr/local/bin/distrosku-restore-test`, weekly Sunday 03:30 UTC, log `/var/log/distrosku-restore-test.log` |
| Off-server backup | **None yet** (no Spaces bucket). Droplet backups are a DO setting, confirm they are enabled |

## 1. Prerequisites (all must be true before the window is scheduled)

1. **Go-ahead and window.** Michael names the date and the freeze window (outside Vega's order hours). Decide the **rollback deadline** now (suggest 48 hours after cutover, see section 12).
2. **A fresh `erp:backup` zip of the instance being moved**, obtained from SiteGround. Path: the instance manager at `dba.distrosku.com` (backup, then download), or `php artisan erp:backup` over SiteGround SSH (as of 2026-10-27 the Grok box could reach neither: SSH port 18765 was closed by the host proxy and the web host answered with a captcha challenge, so a human or an allowed network may have to take and download it). Verify with `erp:backup --list --verify` before copying.
   - The zip contains a copy of the source `.env` (database password, `APP_KEY`). **Treat the zip as a secret.** Transfer by `scp` to `/var/backups/erp/<site>/` (dir mode 0700, owner `www-data`), never through chat or e-mail, and delete the copy on the box after cutover. Never share old zips.
   - Do a **rehearsal restore first** on the staging site with a recent zip (section 5 steps, in `do-staging`) so the real run has no surprises. This also proves the migration path from the source version to 0.20.18 or newer.
3. **APP_KEY difference.** `distrosku-prod-1` has its own new `APP_KEY`. Values stored with Laravel's `encrypted` cast in the database (the SMTP password in Company Settings > Email, the payment gateway keys, the instance-manager secret, any other saved secrets) **cannot be decrypted after a restore**.
   - Default plan: leave the new key and **re-enter** those values by hand in Company Settings and Settings > Payments after the restore (the people who own them type them in; nobody pastes them into chat).
   - Alternative (keeps the values and logged-in sessions): copy the source `APP_KEY` line from the source `.env` into the server `.env` **before** the restore using a file edit that never prints it, then `config:cache`. Only do this if the same site is moving; never reuse one instance's key for another.
   - Inventory before the window: list every encrypted setting (SMTP password, gateway login ID/transaction key/client key, Rules Hub, `dba_secret`) and who re-enters it.
4. **Spaces bucket for off-server backups.** Billable ($5/mo), needs Michael's approval and a bucket-scoped access key created by COS. The key goes only into the server's `rclone` config (chmod 600). Not required to cut over, but **strongly recommended before** production data lands on one disk. Until then, rely on Droplet backups plus manual copies to the box.
5. **DO account items:** Droplet backups enabled (daily), cloud firewall `distrosku-prod-1-fw` allows 22/80/443 (restrict 22 to known IPs if possible), optional reserved IP attached, monitoring alerts for CPU, memory and disk.
6. **DNS access.** Know where the `distrosku.com` zone lives (it is not on DigitalOcean) and who can edit it. Record the current A/AAAA/CNAME/MX/TXT values in a table (outside the repo is fine, they are not secrets) so rollback is exact.
7. **Outbound mail plan.** Port 465 to the SiteGround SMTP host for `notices@vegadist.com` is expected to work from DigitalOcean, but is proven only by the test email in the smoke test. The mailbox password is entered by Michael in Company Settings > Email. Rotate it afterwards (it was shared once in chat).
8. **Reboot the Droplet once, ahead of time** (a kernel update is waiting). After reboot confirm nginx, php8.2-fpm, mariadb, supervisor, fail2ban, cron and ufw are active and the staging site answers. This proves the box survives a restart before real data is on it.
9. **Code version.** Staging runs 0.20.18. Make sure the target version is the same or newer than the source instance, and that the zip to deploy is the one vegatest1 runs. If the source is older, deploy first, then restore, then migrate.
10. **People.** Michael (approver), COS, Brandworx Dev (executor), the DNS owner, and a Vega contact for the smoke test.

## 2. T-7 days: lower the DNS TTL

- At the DNS host, lower the TTL to **300 seconds** on every record that will change (apex and `www` A/AAAA, any `dba.` and `vegatest1.` hosts that move). Do not change values yet.
- Confirm propagation: `dig +noall +answer <name> A` shows TTL <= 300 after the old TTL has expired (wait one old-TTL period).
- Leave MX, SPF, DKIM and DMARC records alone. Mail for `@vegadist.com` is separate from the ERP host.
- **Rollback of this step:** raise the TTL back (for example 3600) any time.

## 3. T-1 day: rehearsal and pre-flight (no customer impact)

1. Rehearsal restore on `do-staging` with the newest source backup (section 5 steps). Time it. Run the smoke test (section 10) on staging.
2. Pre-issue the production certificate if using the DNS challenge (section 7).
3. Confirm free disk (`df -h /`, at least 10 GB free), RAM and swap, `systemctl --failed` empty.
4. Confirm the weekly restore test last ran PASS: `tail -3 /var/log/distrosku-restore-test.log`.
5. Confirm backups: `ls -la /var/backups/distrosku` shows last night's dump and tarball (0600).
6. Take a Droplet snapshot or manual backup from the DO console (billable only by size; get approval) so there is a known-good image of the empty production-ready server.
7. Pre-write the new `.env` values (section 6) in a private file on the server, not in chat.
8. Tell staff the freeze time and what to expect.

## 4. Freeze window (T0)

1. Announce freeze. Staff stop entering orders, POS sales and receiving.
2. **SiteGround side (approved by Michael only):** put the live instance in maintenance mode, or suspend it from the instance manager (suspend pauses scheduled jobs and the queue, backups keep running). Do not delete or modify files. Write down the time.
3. Check the SiteGround queue is empty (no pending jobs, `failed_jobs` unchanged) so nothing is mid-flight.
4. Record row counts of key tables on the source (customers, products, inventories, stock_movements, sales orders, invoices, payments) from the ERP's reports or the backup manifest, for comparison after the restore.

## 5. Final backup and restore

**On SiteGround:**
1. Take the final backup: `php artisan erp:backup --trigger=manual` (or instance manager > Backup now). Verify: `php artisan erp:backup --list --verify` shows `verified`. Note the file name and sha256 (a hash is not a secret).
2. Download it and copy it to the server (see prerequisite 2).

**On `distrosku-prod-1`** (from `/var/www/<site>/laravel`):
1. Confirm the code version: `grep -n "version" config/erp.php | head -2` and `sudo -u www-data php artisan about | head -20`. Deploy the matching upgrade zip first if needed (`distrosku-deploy-endpoint on`, `bash deploy.sh <zip> https://<site>`, then `distrosku-deploy-endpoint off`).
2. Verify the copy: `sudo -u www-data php artisan erp:backup --list --verify`.
3. Restore (takes a safety backup first): `sudo -u www-data php artisan erp:restore <file-name> --force`. Do **not** pass `--with-env` (it would overwrite the server `.env`, including the DB password and the new APP_KEY).
   - It replaces all backed-up tables and writes `storage/app` files. Instance-link settings (`dba_secret`, `install_id`, suspend flags) are preserved by design.
4. If the restored code version differs: `sudo -u www-data php artisan migrate --force`.
5. `sudo -u www-data php artisan config:clear` (and see section 6 for `config:cache`).
6. Compare counts against step 4 of the freeze window. Example: `mariadb -N -e "select count(*) from <db-name>.customers"` (use the real DB name, never paste the DB password; root uses the unix socket).
7. Check `storage/app` ownership after restore: `chown -R deploy:www-data storage/app; find storage/app -type d -exec chmod 2775 {} +`.
8. Re-enter the encrypted settings (prerequisite 3): Company Settings > Email, payment gateway, Rules Hub, instance link.
9. Note: while the mail gates are still on (next section), the mail guard will wipe SMTP settings every minute. Do section 7.1 before entering SMTP.

**Rollback of this step:** `erp:restore <pre-restore safety zip>` restores the previous staging data. Nothing outside the server is affected.

## 6. Switch `.env` to production values

Edit `/var/www/<site>/laravel/.env` with a file editor on the server (never print it, never open it in a web editor). Keep it `640 deploy:www-data`. Back it up first: `cp -a .env /root/env.pre-cutover` (root-only, then delete after the rollback deadline).

| Key | Staging | Production |
|---|---|---|
| `APP_ENV` | `staging` | `production` |
| `APP_DEBUG` | `false` | `false` (must stay) |
| `APP_URL` | `https://165-245-139-132.sslip.io` | `https://<real domain>` |
| `APP_TIMEZONE` | `America/New_York` | `America/New_York` |
| `LOG_LEVEL` / `LOG_DAILY_DAYS` | `warning` / `14` | same |
| `MAIL_MAILER` | `log` | `smtp` only if `.env` carries mail; otherwise leave `log` and use Company Settings > Email (the ERP's saved Company Settings > Email values are used by the app; the env mailer is the fallback) |
| `ERP_INSTANCE` | `do-staging` | the real instance name |
| `ERP_BACKUP_PATH` | `/var/backups/erp/do-staging` | `/var/backups/erp/<instance>` (0700, `www-data`) |
| `ERP_DBA_SECRET` | empty (staging is not linked to the instance manager) | the production value, set by file edit only when re-linking to the instance manager (section 9) |
| `QUEUE_CONNECTION`, `SESSION_DRIVER`, `CACHE_STORE` | `database` | `database` |

Then: `sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache && sudo -u www-data php artisan view:cache` (the config **is cached on this server**, so edits to `.env` have no effect until this runs), `sudo supervisorctl restart distrosku-queue`, and re-check `/up`.

## 7. Remove the staging gates (mail block, basic auth, noindex), and certbot

Order matters. Do these only after the restore is verified and Michael says go.

**7.1 Remove the mail block** (needed for dunning and any outbound mail):
```
rm /etc/cron.d/staging-mail-guard                       # stops wiping SMTP settings
mv /usr/local/sbin/staging-mail-guard /root/staging-mail-guard.disabled
ufw status numbered                                     # rows 4-7 (and v6 rows 11-14 on the staging build) are the DENY OUT 25/465/587/2525 rules
for p in 25 465 587 2525; do ufw delete deny out $p/tcp; done   # removes the IPv4 and IPv6 rule
ufw status verbose                                      # confirm only 22/80/443 IN remain and no outbound denies
```
Keep inbound at 22, 80 and 443 only. Then enter Company Settings > Email, press **Send test**, and only after a real test mail arrives turn on **Auto-email dunning**.

**7.2 Basic auth and noindex.** In `/etc/nginx/sites-enabled/do-staging` (copy the file to the real domain name first, `server_name <real domain> www.<real domain>`):
- delete the two `auth_basic` lines and move `/etc/nginx/distrosku.htpasswd` to `/root/` (delete after the rollback deadline);
- delete the `add_header X-Robots-Tag "noindex, nofollow, noarchive" always;` line (line 7) and the whole `location = /robots.txt { ... }` block, or replace the block with a real `robots.txt` (the ERP is private, so keeping `Disallow: /` for `/admin`, `/pos`, `/portal` is reasonable);
- keep the security headers, `server_tokens off`, the PHP-only-via-`index.php` rule and the dotfile deny rules;
- `nginx -t && systemctl reload nginx`.
Consider keeping an IP allow-list or rate limit on `/admin/login` (`limit_req`) because the basic-auth layer is gone. The fail2ban `nginx-http-auth` jail then no longer matches anything, which is fine.

**7.3 Certificate (certbot) for the real domain.** The staging cert is for the sslip.io name only.
- Option A (recommended, no outage): use the DNS challenge before DNS moves: `certbot certonly --manual --preferred-challenges dns -d <domain> -d www.<domain>` and add the TXT record the tool prints at the DNS host (a DNS edit, so it needs the DNS owner and Michael's yes). Then point nginx at `/etc/letsencrypt/live/<domain>/`. Manual DNS-challenge certs do not auto-renew, so after cutover switch to the HTTP challenge: `certbot --nginx -d <domain> -d www.<domain>` (renewal then runs from the `certbot.timer`).
- Option B: flip DNS first, then `certbot --nginx -d <domain> -d www.<domain>` (HTTP challenge needs DNS already pointing here). Browsers show a certificate warning for a few minutes. Not advised for a business-hours cutover.
- Confirm `certbot renew --dry-run` passes and `systemctl list-timers | grep certbot` shows the timer.
- Check headers: `curl -sI https://<domain>/ | grep -i -E "strict|x-frame|x-content|referrer|server"`. HSTS is already set (6 months, no `includeSubDomains`, no preload).

**Rollback of this step:** restore `/root/staging-mail-guard.disabled` and the cron file, re-add the ufw out rules (`ufw deny out 25/tcp` etc.), re-add the `auth_basic`, `auth_basic_user_file /etc/nginx/distrosku.htpasswd` and `X-Robots-Tag` lines (copy the htpasswd file aside instead of deleting it until the rollback deadline has passed). `/root/hardening-backup-20261028/nginx/` holds the nginx config from before the hardening pass.

## 8. Queue and scheduler checks

Run all of these before DNS moves (use `curl --resolve <domain>:443:165.245.139.132 https://<domain>/up` to test the real name before DNS points here):
1. `sudo supervisorctl status` shows `distrosku-queue RUNNING` (uptime not resetting).
2. `crontab -l -u www-data` has the `schedule:run` line; `sudo -u www-data php artisan schedule:list` shows the nightly jobs (erp:backup 01:30 ET, dunning:send 07:00 ET, rules:sync, cycle-plan, etc. all in the app time zone, not the server's UTC).
3. Dispatch a test: from Admin trigger something that queues (for example a test email from Company Settings > Email, or any export that runs as a job), then `tail -n 20 /var/log/supervisor/distrosku-queue.log` shows it processed. `select count(*) from failed_jobs` stays 0.
4. `sudo -u www-data php artisan erp:backup --trigger=manual` succeeds and lists `verified`.
5. `sudo -u www-data php artisan dunning:send` is a no-op unless the setting is on (do **not** run it for real until Michael turns it on).
6. `systemctl --failed` is empty; `df -h /` has space; `free -m` shows swap unused.
7. Nightly local dump will run at 02:15 UTC: `tail -3 /var/log/distrosku-backup.log` after the first night.

## 9. Smoke-test checklist (staff plus Brandworx Dev, on the real hostname via `--resolve` or hosts-file entry, then again after DNS)

Mark each PASS/FAIL with initials and time.
- [ ] `/up` returns 200; `/admin/login` loads with CSS/JS (no mixed-content or 404 assets).
- [ ] Admin login works for a real admin; logout/login again; wrong password is rejected.
- [ ] Dashboard tiles and a report load with the restored numbers (compare 3 figures with SiteGround).
- [ ] **POS:** open `/pos`, ring a test sale with a restored product, pay by the test tender, receipt prints/renders, inventory decrements, void it.
- [ ] **Sales order:** create an order for a test customer, allocate/pick it, invoice it; totals and tax lines look right.
- [ ] **Statement PDF:** generate a customer statement (dompdf), open the PDF, check logo, customer, balance and aging match SiteGround.
- [ ] **Uploads/downloads:** a license attachment, COA PDF, product image and the company logo all open (this exercises `storage/app` permissions).
- [ ] **B2B portal login** for a test portal user and place a test order.
- [ ] **Company Settings > Email:** host, port, user, from-address entered; **Send test email** received at a real inbox; check SPF/DKIM result in the headers.
- [ ] **Dunning test email:** run the dunning preview/test for one test customer, confirm it arrives from `notices@vegadist.com`, the content and amounts are right, and nothing went to real customers. Only then ask Michael whether to switch Auto-email dunning on (the staging mail guard deleted the saved `dunning_auto_email`, `mail_host`, `mail_username` and `mail_password` settings, so check each one is set the way production needs).
- [ ] Payment gateway: re-entered keys, a **sandbox** or test transaction only. No live card is charged during the smoke test.
- [ ] Instance link (instance manager) shows the instance healthy after section 9 step.
- [ ] Logs clean: `tail -n 50 storage/logs/laravel-$(date +%F).log` has no new errors; `/var/log/nginx/error.log` has no 5xx bursts.

**Instance manager re-link.** On the instance manager (`dba.distrosku.com`) change the registered instance URL to the new host and set the shared secret by file edit/UI entry (never in chat). Confirm `status` shows the new version. If `dba.distrosku.com` itself moves, repeat this runbook for it as its own window.

## 10. DNS cutover

Only when sections 5-9 pass and Michael says go.
1. Nothing may change on SiteGround after the final backup. If anyone entered data there after it was taken, stop, take a new final backup and restore again.
2. At the DNS host change the A (and AAAA if present) records of the moved names to `165.245.139.132` (or the reserved IP). Keep the old values in the rollback table. TTL is already 300.
3. Verify from several resolvers: `dig +short <domain> @1.1.1.1`, `@8.8.8.8`; `curl -sI https://<domain>/up` returns 200 with the new certificate; the `Server` header shows nginx without a version.
4. Ask staff to log in and place one real order. Confirm in the queue log and the database that it arrived **here** (not on SiteGround).
5. Lift the freeze. Keep SiteGround in maintenance/suspended state, **untouched**, as the rollback copy.

## 11. Post-cutover monitoring (first 48 hours, then weekly)

- **UptimeRobot** (free tier, or paid if alerts by SMS are wanted): HTTPS monitor on `https://<domain>/up` every 5 minutes; a keyword monitor on `/admin/login` (pick a word that is on the login page, for example the page title); SSL expiry and domain expiry monitors; alerts to Michael and COS. Add the monitor after basic auth is removed (or with HTTP credentials until then). Status page optional.
- DigitalOcean monitoring alert policies: CPU > 80% for 10 min, memory > 85%, disk > 80%, plus a droplet down alert.
- Daily for the first week: `systemctl --failed`, `fail2ban-client status`, `tail -3 /var/log/distrosku-backup.log`, `tail -3 /var/log/distrosku-restore-test.log`, `supervisorctl status`, queue backlog `select count(*) from jobs`, `failed_jobs`, disk and swap.
- Watch the first nightly runs: `erp:backup` 01:30 ET (zip appears and verifies), local dump 02:15 UTC, `rules:sync` 02:30 ET, `dunning:send` 07:00 ET (only if on).
- Capacity: if CPU stays above 60% or the box swaps, plan a resize to the next Droplet size (a brief downtime; a CPU/RAM-only resize can be reversed, growing the disk cannot). Billable, needs approval.
- Keep SiteGround intact for at least two weeks and until one restore drill from the off-server backup has passed (see the Phase B notes in `do-rebuild-plan.md`).
- Housekeeping after the rollback deadline: delete the copied source zip and `/root/env.pre-cutover`, rotate the SMTP mailbox password, and rotate any credential that was ever shared in chat.

## 12. Rollback plan (point DNS back to SiteGround)

Decide the **rollback deadline** in advance (suggested: 48 hours). Up to then the SiteGround copy is a complete, intact, suspended instance.

1. **Trigger:** a smoke-test failure that cannot be fixed within the window, data mismatch, mail or payment failure, or Michael's call.
2. **Before DNS has moved:** nothing to undo outside the server. Lift SiteGround maintenance, tell staff to resume, and stop (the Droplet can stay as is).
3. **After DNS moved, no new orders taken on DigitalOcean yet:** at the DNS host set the A/AAAA records back to the recorded SiteGround values (TTL 300 means about 5 minutes); lift SiteGround maintenance/suspension; verify `curl -sI https://<domain>/up` is served by SiteGround; tell staff to resume.
4. **After real orders were entered on DigitalOcean:** first freeze DigitalOcean (set `APP_ENV` maintenance with `php artisan down` or re-enable nginx basic auth), take `erp:backup` there, download it, then DNS back, then restore that backup onto SiteGround (instance manager > Restore, or `erp:restore` over SSH) so the new orders are not lost, and compare counts. This is the risky path; the deadline exists to limit it.
5. After rollback: write down what failed, keep the Droplet for diagnosis, and re-plan. Do not delete anything on either side.
6. Mail: because DO and SiteGround both send as `notices@vegadist.com`, make sure auto-email dunning is **off** on whichever side is not live.

## 13. Reference: switches and commands (no secrets)

| Task | Command / file |
|---|---|
| Site health | `curl -s -o /dev/null -w '%{http_code}\n' https://<host>/up` |
| Services | `systemctl is-active nginx php8.2-fpm mariadb fail2ban supervisor cron ssh` |
| Firewall | `ufw status verbose` (in: 22/80/443; staging also has out-denies 25/465/587/2525) |
| Banned IPs | `fail2ban-client status sshd`, `fail2ban-client status nginx-http-auth` (unban: `fail2ban-client set <jail> unbanip <ip>`) |
| Deploy endpoint | `distrosku-deploy-endpoint on|off|status` (off = `/deploy.php` returns 404; use only during an upgrade) |
| Dump now | `/usr/local/sbin/distrosku-dbdump` (log `/var/log/distrosku-backup.log`) |
| Restore test now | `/usr/local/bin/distrosku-restore-test` (log `/var/log/distrosku-restore-test.log`, PASS/WARN/FAIL) |
| App backup / restore | `sudo -u www-data php artisan erp:backup`, `erp:restore <file> --force` |
| Config changes | edit `.env`, then `sudo -u www-data php artisan config:cache` (config is cached) |
| Config backup from hardening | `/root/hardening-backup-20261028/` (root-only; sshd, fail2ban, nginx, mysql, php, env, apt, cron, sbin) |
| Reboot-needed check | `ls /var/run/reboot-required` |
