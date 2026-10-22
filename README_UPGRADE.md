# Vega ERP — Phase 20.3 UPGRADE (existing install at vegatest1.distrosku.com)

For a site already installed from `vega-erp-phase1.zip` and upgraded through phase 3 (a phase-2 site can also take this zip: seeders are cumulative). TEST data only. No secrets in this package.
This zip contains **no `laravel/.env` and no `laravel/storage/`**, so extracting it over the live site keeps both.

## What's new in 0.20.3 (includes everything through 0.20.2)

**Customer portal: sections, Buy again, catalog by category; fixed customer types.**

- **Products > edit > Customer portal sections**: *Show in Promotions*, *Show in New products* (optional *New until* date; blank = until unticked), *Show in Featured*, and a *Section sort order* (lower first). Changes are in the Activity log (Product *updated*).
- **Portal home** shows **Promotions**, **New products** and **Featured** above the catalog (hidden while searching or filtering). Promotions = ticked products **plus** products with an active product-specific promotion (Pricing > Promotions) that applies to this customer today. Same rules as the catalog: inactive/archived hidden, customer price (lowest wins), compliance shown as *Unavailable*.
- **Buy again** (portal menu, or the *Buy again* tab): products the customer bought on Delivered/Invoiced orders, with last ordered date, last qty, current price, a qty box and *Add to cart*; plus the last 10 orders with **Reorder all from SO…**.
- **Catalog grouped by category** (department, else product category, else Other), each collapsible, with *Expand all* / *Collapse all*. Search and the department filter still work across categories and open the matching groups.
- **Customer type** is now a dropdown: C-store, Liquor store, Smoke shop, Retailer, Restaurant, Food truck, Wholesale distributor, Other. Existing values are mapped by the migration (wholesaler/subjobber/distributor => Wholesale distributor; retailer => Retailer; close matches to C-store/Liquor/Smoke shop/Restaurant/Food truck; anything else => Other). The original value is kept in `type_legacy` and shown under the field. Customers list has a *Customer type* filter.
- KY 73A422 / subjobber cigarette reports: Wholesale distributor (and legacy wholesaler/subjobber/distributor) = licensee (1B/2B); others 1C/2C as before. MSA class of trade: C-store/Liquor/Smoke shop/Retailer => Retailer, Wholesale distributor => Distributor. Customer imports accept the new names.
- BrandWorx Marketing tag becomes `type:<new type>` (e.g. `type:wholesale-distributor`); old `type:` tags are not removed.
- **Migration** (re-runnable): new product portal columns, `customers.type_legacy`, type mapping. Run the upgrade page as usual.

## What's new in 0.20.2 (includes everything through 0.20.1)

**Warehouse users can update inbound tracking.**

- New **Update tracking** button on the purchase order page, on each row of the Purchase orders list and on **Purchasing > Expected inbound**.
- It edits **only** the inbound shipping fields: *Expected arrival*, *Carrier*, *Carrier name*, *Tracking number(s)* and *Custom tracking URL*. Prices, lines, vendor, warehouse and status cannot be changed from it.
- Who: **Warehouse**-role users (permission `purchase_orders.update_tracking` in `config/erp.php`) and members of the PO warehouse's **Receiving team**. Owners/admins keep full edit.
- Warehouse users now open POs on a read-only PO page (with Update tracking) instead of the edit screen.
- Changes are still in the Activity log and still send the receiving-team alert.
- No migration. Run the upgrade page as usual.

## What's new in 0.20.1 (includes everything through 0.20.0)

**Inbound purchase orders: tracking and receiving-team alerts.**

- **Inbound shipping** section on the purchase order: *Expected arrival*, *Carrier* (UPS, FedEx, USPS, DHL, LTL / freight / other with a carrier name), *Tracking number(s)* (several allowed) and an optional *Custom tracking URL* (overrides the carrier link; `{tracking}` is replaced by each number).
- **Track** links (open in a new tab) on the PO page, the PO list (*Expected* and *Tracking* columns), the warehouse scanner hub and the scan-receive screen. UPS/FedEx/USPS/DHL links are built automatically; LTL/other needs a custom URL.
- **Receiving-team alerts** (bell icon, top right): when a PO is set to **Ordered** (sent to the vendor), when its ETA, carrier or tracking changes, and a **morning digest at 6:30** (POs due today, tomorrow and overdue) per warehouse.
  - Who: the warehouse's **Receiving team** (Warehouses > edit > *Receiving team*). If none is set: every active **Warehouse**-role user; if there are none, owners/admins.
  - Email is sent too **only when mail is configured** (not on vegatest1); without mail, alerts are bell-only and never error.
- **Purchasing > Expected inbound** page and a dashboard widget for the receiving team: open (Ordered/Partial) POs grouped Overdue / Today / Tomorrow / This week / Later / No ETA, products and quantity still outstanding, carrier + Track, and **Start receiving** (scanner).
- Field changes are in the Activity log (PO *updated*), plus `po_receiving_alert` and `po_receiving_digest` entries.
- **New order for the Modules launcher and the sidebar**: POS, Sales Orders, Customers, Payments, Pricing, Promotions, Products, Vendors, Purchasing, Warehouse, Inventory | Delivery, Driver, Van Sales, Accounting, Bills (AP) | Reports, Tax Filings, MSA Weekly, PACT Reports, Compliance | Users, Settings, Imports. Sidebar groups follow the same order (Payments, Promotions, Products, Warehouse, Delivery, Bills (AP) and Tax Filings are now their own groups; Users, Settings and Imports are under Settings). Marketing, Admin and Platform stay at the end.
- **Migration** (re-runnable): new PO columns, `warehouse_receivers` table, `notifications` table. Run the upgrade page as usual.

## What's new in 0.20.0 (includes everything through 0.19.6)

**Order to cash in the fewest clicks.** A normal order is now 3 clicks (Save & confirm, Deliver & invoice, Record payment); cash on delivery is 2 (Save & confirm, Deliver, invoice & collect).

- **Save & confirm** on the new sales order form: saves and confirms in one click (compliance, credit limit, stock reservation as before). If confirm is blocked, the order is saved as a **Draft** and the reason is shown. **Save as draft** still saves without confirming.
- **Delivering always invoices.** *Deliver & invoice* (was *Deliver*), a driver marking a stop delivered, pickup signature, POS and van sales all deliver and post the invoice (GL, buydowns) in one step. Signatures for regulated items are still required. Compliance is still checked at confirm only.
- **Record payment** on any Delivered or Invoiced order with a balance (a Delivered order is invoiced first, so orders stuck at Delivered such as SO000001 can be closed in one step). Amount defaults to the open balance; partial payments allowed. Methods: Cash, Check (**check # required**), Card (manual), ACH, **On account** (records no payment). On account that puts the customer over the credit limit is blocked unless an **owner/admin** enters an override reason (Activity log). *Charge card / ACH* (gateway) is unchanged.
- **Deliver, invoice & collect**: one step for cash on delivery.
- All steps are **buttons at the top of the order page** (only the ones that apply show), and still in the list row menu.
- **Progress chips** on the orders list: Confirmed > Delivered > Invoiced > Paid / Partial (from the balance; no new stored status). New **Payment** filter: Unpaid / Partial / Paid, and a Balance column.
- **Sales > Receivables**: delivered-not-invoiced and invoiced-unpaid orders, age and aging bucket (Current / 30 / 60 / 90+ days from invoice date), customer, stage and aging filters, bulk **Invoice selected**, and **Record payment** per row. *Invoice selected* is also a bulk action on the orders list.
- **Fix: Payments > New** (now *Record payment*) no longer saves a bare payment row: you pick an open order and it posts to the ledger and updates the order balance, same as Record payment on the order. Payments are not editable or deletable.
- Every payment and every on-account decision is in the Activity log (`payment_recorded`, `order_on_account`, `credit_override`).
- **No migration** in this release. Run the upgrade page as usual (clears caches).

## What's new in 0.19.6 (includes everything through 0.19.5)

- **Every sale is Counter pickup unless you choose Delivery.** Fulfillment is now chosen on the sale (POS, van sale, portal, office sales orders), not taken from the ship-to.
  - Counter pickup needs **no ship-to**: no ship-to field, no "No ship-to" warning. Customers with no ship-to on file (e.g. a new account) can buy at the counter.
  - Choosing **Delivery** shows the ship-to picker, prefilled with the customer's default ship-to; a delivery cannot be saved without one.
  - Only **Delivery** orders are put on delivery routes / the driver app.
  - Invoices, pick lists, packing lists and delivery receipts print **Counter pickup** for pickup sales.
  - Compliance for a pickup sale is checked against the customer's default ship-to when they have one (same as before), otherwise against the selling warehouse.
- **Migration** (re-runnable): order ship-to becomes optional; orders without a fulfillment are set: open ones to Counter pickup (unless already on a delivery stop), closed POS sales to Counter pickup, other closed orders to Delivery. Ship-tos already on orders are kept. Logged as `order_fulfillment_migrated`.

## What's new in 0.19.5 (includes everything through 0.19.4)

- **Fix: POS ship-to blank.** Picking a customer on the POS now always selects a ship-to: the default one, else the only / oldest one. A ship-to that does not belong to the selected customer (left over from a saved cart, a recalled held sale or a stale screen) is replaced instead of showing blank.
- If the customer has **no ship-to at all**, the POS shows **"No ship-to: add one"** with a link to the customer page (Ship-to addresses tab). Customers created in the admin or by import only get a billing address; a ship-to is a separate record.
- The ship-to can still be changed from the list; the default is marked "(default)" and listed first.
- **Van sale** (driver app) picks the default ship-to (it used to take an arbitrary one), always shows the ship-to, and shows "No ship-to" when there is none.
- **Portal** opens on the customer's default ship-to (it used to take the first by name).
- No migration in this release.

## What's new in 0.19.4 (includes everything through 0.19.3)
- **Counter pickup is the default fulfillment.** New field **Fulfillment** on every ship-to (Counter pickup / Delivery (route)) and on every order (Counter pickup / Delivery / Ship).
  - New ship-tos default to Counter pickup. **The migration sets every existing ship-to to Counter pickup** (the count is written to the log and the Activity log as `shipto_fulfillment_migrated`).
  - New orders take the ship-to's fulfillment (sales order form, portal); POS = pickup, van = delivery. On the sales order form it can be changed per order.
  - **Impact:** delivery days, route names and route sequence stay on the ship-tos as data, but **Plan route no longer adds counter-pickup orders**. To keep delivering to a customer, set that ship-to (or the order) to **Delivery (route)**. Orders created before the upgrade keep the old behaviour (still delivery candidates). Manifests already built and the driver app are not changed.
  - Regulated products still need the signature at pickup (Sales Orders > Pickup (signature)) and at the counter.
- Migration `2026_11_15_000001_phase194_counter_pickup_default` (re-runnable; the flip runs only once, when the field is first added). Run the upgrade page as usual.

## What's new in 0.19.3 (includes everything through 0.19.2)
- **Ship-to addresses now live on the customer page.** *Customers > (customer) > Ship-to addresses* tab: add, edit and delete ship-tos inline with all fields (address, city, **county**, state, zip, delivery days, route, phone, MSA fields) and a licenses count. **Default ship-to**: one per customer (the first is default automatically; **Make default** on any row). New orders, POS and marketing use the default.
- *Ship Tos* is gone from the menu; the old screen still opens by URL and has an **Open customer** button. Bulk delete removed there.
- A ship-to used on orders cannot be deleted (message shown).
- Permissions unchanged (Sales view/manage; Warehouse/Driver none; full roles all).
- **POS: Browse catalog.** Big button next to the scan box opens a touch browser: category and brand chips, a filter box, tiles with name, SKU / unit, the price for the selected customer and stock at this warehouse. Tap a tile, enter the qty on the keypad, **Add to sale**. Same pricing and eligibility / regulated checks as scanning.
- **POS: Hold / Recall.** **Hold** saves the cart (customer, ship-to, lines, prices, tender, notes) with an optional label, cashier and register, then clears the screen. **No stock is reserved.** **Recall** lists held sales at this warehouse (label, customer, items, total, time, cashier); any user at that location can recall; owners/admins can delete. On recall the lines are re-priced and eligibility, stock and credit are re-checked; changes are shown in yellow. Held sales expire after *Admin > Settings > POS: held sales expire after (days)* (default 7; nightly 00:15). Hold, recall, delete and expiry are in the Activity log. A held sale (and the cart in progress) survives the PIN lock. New **Notes** box on the POS (saved on the order).
- Migration `2026_11_14_000002_phase193_pos_held_sales` (re-runnable).
- Migration `2026_11_14_000001_phase193_ship_to_default` (adds the default flag and sets each customer's oldest ship-to as default; re-runnable). Run the upgrade page as usual.

## What's new in 0.19.2 (includes everything through 0.19.1)
- **BrandWorx Marketing sync is ONE-WAY: ERP -> Marketing.** The 0.19.1 inbound pull, Preview/Sync-now page, Leads list and `/hooks/marketing` webhook are removed (the migration drops `marketing_leads` and the old inbound settings; re-runnable).
- **Consent:** the ERP opt-in switches drive email/SMS consent. Opt-in off -> DND on for that channel. Opt-in on clears DND only on contacts the ERP itself created; DND set in Marketing on pre-existing contacts is never wiped.
- **Contact custom fields:** Vega Account Number = customer code, State Tax ID (new customer field), Resale certificate (newest `resale` license number). Mapped by field key or ID in Marketing settings, looked up via the API at sync time. Only text fields are written; empty ERP values are never sent.
- **Nightly (02:45):** only customers not yet linked to a contact are sent. Edits still sync right away through the queue.
- **Push all customers** button (Marketing settings, admin): queues every active customer, 20 per 10 seconds.

### Cron on SiteGround (required)
One cron job, every minute: `cd ~/www/vegatest1.distrosku.com/public_html/laravel && php artisan schedule:run >> /dev/null 2>&1` (adjust path). The scheduler drains the database queue each minute with `queue:work --stop-when-empty --max-time=50`; no separate worker/supervisor is needed.

## What's new in 0.19.1
- **Lowest price wins.** Every available price (special prices, contract/group/qty-break/dated rules, list) is compared; the lowest wins and the order line shows its source. Promos still stack on rule/list prices. A one-time special price is used up only when it actually won.
- **Sales can view** all special prices (no edit).
- **Invoicing no longer re-checks compliance**; eligibility is checked once, at order confirm.
- **BrandWorx Marketing inbound sync** (Admin → Sync from BrandWorx Marketing): dry-run Preview, Sync now, scheduled pull (default every 15 min, 0 = off), optional workflow webhook (`POST /hooks/marketing`, header `X-Vega-Secret`). Contacts never auto-create customers; unmatched contacts become Leads an admin can convert. Nightly full outbound reconcile at 02:45.
- Sending stays **OFF** by default.

### After extracting
```
php artisan migrate --force
php artisan optimize:clear
```
Cron must already run `php artisan schedule:run` every minute and the queue worker. Then in Marketing settings enter the location ID and token (never in files), Preview, then Sync now.

**19 (v0.19.0): Customer special prices + BrandWorx Marketing add-on.** Goes on top of 0.18.2 (or any earlier site; includes everything through 0.18.2). New migration `2026_11_11_000001_phase19_overrides_ghl` (re-runnable). No new seeders. **Run the upgrade page as usual.** Nothing is sent anywhere after the upgrade: the marketing module is off and hidden until set up.
- *Pricing > Customer special prices*: a price for one product, for **one customer or all customers**, **One time** (used by the first confirmed order line, then marked Used with the order number), **Date range** (from/to) or **Ongoing**. Owners/admins create, edit and delete; Sales can view only; the server refuses changes from other roles (logged as `price_override_blocked`). Every change is in the Activity log; a one-time use is logged as `price_override_used`.
- **Price order:** customer special price > all-customers special price > contract (customer) > group > qty break / dated > list. Promotions still stack on contract / group / list prices, but **not** on a special price (a special price is the final price). Applies to sales orders, POS, van and the portal (one engine). A typed-in line price is still a manual override (log only, unchanged).
- If two drafts carry the same one-time price, the first confirmed uses it; the second confirm is refused with "re-enter the line to re-price it".
- *Settings > BrandWorx Marketing* (owner/admin): Private Integration Token (stored encrypted, never shown again, "Set (ends …abcd)"), **Location ID** (required by the API), App URL (default https://app.brandworx.ai, used for "Open in BrandWorx Marketing" links), optional Inbound webhook URL, **Module on**, **Send transactional email / SMS** (off), templates. **Test connection** makes read-only calls only.
- When on: customers sync to contacts (queued, retried 5x with backoff; tags `vega`, `type:*`, `state:*`, `route:*`, `email-opt-in`/`sms-opt-in`; channels not opted in are set to DND). Order events add tags `erp-order-placed`, `erp-order-delivered`, `erp-invoice-ready`, `erp-invoice-due` (3 days before), `erp-invoice-overdue` (daily 07:00) and, if set, POST to the inbound webhook, so BrandWorx Marketing workflows can react. *Marketing > Marketing sync log* shows every attempt (no tokens, no message text). Customers: new **Email opt-in** / **SMS opt-in** switches and an **Open in BrandWorx Marketing** button.
- Transactional notifier (order confirmed, delivery scheduled, invoice ready): sends only when the module is on, sending is on, the template is on, and the customer opted in to that channel. Default: no sends. vegatest1 has no mail.

**18.2 (v0.18.2): KY registry vape check switched off.** Goes on top of 0.18.1, 0.18.0 or 0.17.3 (includes all of 0.18.0 and 0.18.1). New migration `2026_11_10_000001_phase182_ky_registry_disabled` (re-runnable). No new seeders. **Run the upgrade page as usual.**
- The built-in rule **KY: THC vape must be on the CHFS hemp product registry** is now **disabled** (new installs seed it disabled; a site where 0.18.1 enabled it is switched off). Its note starts "DISABLED until CHFS lists UPCs on the registry". Nothing is blocked by the KY registry, so THC vapes sell into KY as before.
- *Compliance > KY hemp registry* stays (fetch, upload, link, mark listed) for preparing listings; it shows that the rule is off. To turn the check on later, enable the rule under Compliance > Eligibility rules.
- Blocked POS / van attempt logging from 0.18.1 is unchanged (`eligibility_blocked`, `pos_sale_blocked`, `van_sale_blocked`).
- In the 0.18.1 notes below, the rule ships disabled and the "load the registry before selling THC vapes into KY" step no longer applies.

**18.1 (v0.18.1): KY hemp product registry for THC vapes.** Goes on top of 0.18.0 or 0.17.3 (includes all of 0.18.0). New migration `2026_11_09_000001_phase181_ky_hemp_registry` (re-runnable): product Form + "contains hemp-derived cannabinoid" flag, rule "Only" selector, registry copy table, listing source (manual / registry / note). No new seeders. **Run the upgrade page as usual.**
- New built-in rule **KY: THC vape must be on the CHFS hemp product registry** (enabled, Block, state KY, effective the day the migration runs; the CHFS report shows no dates). Source: KY CHFS "Hemp-derived Cannabinoid Registered Products" public report, https://redcap.chfs.ky.gov/surveys/?__report=LXDTXPJH9E4RAY8R .
- **THC vape** = vape form (Form "Vape", Subtype "Vape", or tax class Vapor) **and** a cannabinoid (Subtype Hemp or CBD, Hemp type set, the new cannabinoid flag, synthetic cannabinoid, or any THC mg). Nicotine-only vapes are not affected. Zero-THC CBD vapes count.
- *Compliance > KY hemp registry*: **Fetch now from CHFS** (reads the public report; the public page has no CSV export) or **Upload CSV / XLSX** (Record ID, Brand of Product, Full name of the product, Type of product). Products are listed automatically only on an exact brand + name (or name) match after ignoring case and punctuation; the registry has no SKU or UPC. Close matches are shown as suggestions with a **Link** button. **Mark listed / not listed** sets a manual listing from a date. Products dropped from the registry lose automatic listings (manual ones are kept); a partial file (under 80% of the copy on file) never removes anything.
- **After installing: load the registry (Fetch now) before selling THC vapes into KY**, otherwise those sales are blocked at confirm.
- Rules are checked only at order confirm (admin, POS, van, portal), not again at delivery or invoice.
- Blocked POS / van attempts are now always in the Activity log: `eligibility_blocked` (now kept even though the sale is rolled back), `pos_sale_blocked`, `van_sale_blocked`.
- Federal hemp rule unchanged: still disabled, no threshold.

**18 (v0.18.0): Product eligibility registry + hemp/THC gating.** Goes on top of 0.17.3 (or any earlier site; includes everything). New migration `2026_11_08_000001_phase18_eligibility` (re-runnable): new product fields, COAs, state vape directory listings, eligibility rules, sales order ship date + eligibility override. No new seeders (the two built-in rules are created by the migration). **Run the upgrade page as usual.**
- *Products > Edit > Eligibility*: subtype (CBD / Hemp / Kratom / Vape), total THC per container (mg), THC per serving (optional), synthetic / converted cannabinoid, flavored, FDA marketing authorization (authorized / pending PMTA / denied / none). Tabs **COAs** (upload file, lab, batch/lot, test date, expiry; status Current / Expiring soon / Expired) and **State vape directories** (vape SKUs).
- *Compliance > Eligibility rules*: federal or state rules (optional county / city), products by subtype / tax class, fire on THC over a configurable limit, synthetic, flavored, FDA status, not on the state vape directory, not on the MSA directory, no current COA; Block or Warn; effective from / to; source note; version bumps on each change. Built-in: **State MSA directory** (enabled; the existing MSA blocking now runs here, same wording, can be switched off) and **Federal hemp definition change (DRAFT, verify with counsel)**, effective 2026-12-11, **disabled, no THC threshold entered**; set it only after counsel review.
- Enforced on Confirm everywhere (admin, POS, van, portal) by ship-to state / county / city on the order date and the new optional **Ship / delivery date**. Warnings are saved on the order. Owners/admins: *Sales Orders > ⋮ > Confirm with eligibility override* (reason required, logged; PACT / license / stock checks still apply).
- *Compliance > Inventory at risk*: per state, stocked SKUs that become blocked within N days: on hand, value at cost, days left, sold/day (last 30 days into that state), projected unsold at cutoff; "Include draft rules" previews the federal placeholder. Also lists COAs expired or expiring (Settings: warn days, default 30).

**17.3 (v0.17.3): brandworx_admin role assignment locked down.** Goes on top of 0.17.2 (or any earlier site; includes everything). **No new migration, no new seeders.** Run the upgrade page as usual.
- Only a BrandWorx admin can give a user the **brandworx_admin** role, on create or edit. The option no longer appears in the Role list for owners and admins, and the server refuses it from any route (forms, bulk actions, imports). Each blocked attempt is recorded in the Activity log as `bwx_protect_blocked`.
- A BrandWorx admin's **name** is now protected like its email: only another BrandWorx admin can change it.
- **Deleting customers** is now limited to owner / admin / brandworx_admin. Sales and other roles no longer see Delete (edit page) or the bulk Delete, and the server refuses it from any route. Blocked attempts are written to the Activity log (`customer_delete_blocked`).
- Command-line / seeding is not affected.

**17.2 (v0.17.2): Credit-limit editing and BrandWorx admin protection.** Goes on top of 0.17.1 (or any earlier site; includes everything). **No new migration, no new seeders.** Run the upgrade page as usual.
- **Credit limit** on a customer can now be set or changed only by owner / admin / brandworx_admin. For Sales and other roles the field is read-only, and the server refuses the change from any path, including imports (the importer runs as the admin who started it).
- POS / van block message now reads "…Collect payment, or an owner/admin can confirm it as a sales order in admin with a credit override (Sales Orders, row menu, Confirm with credit override)."
- **BrandWorx admin accounts** can only be deactivated, deleted, re-roled, password-reset or have their email changed by another BrandWorx admin. Owners and admins no longer see Edit / Deactivate / Reset password / Delete on those accounts, and the server blocks it from any path (including bulk actions and imports). This changes 0.16, where an owner could reset a BrandWorx admin's password. Each blocked attempt is written to the Activity log (`bwx_protect_blocked`).

**17.1 (v0.17.1): Customer credit limits enforced.** Goes on top of 0.17.0 (or any earlier site; includes everything). New migration `2026_11_07_000001_phase171_credit_limit` (credit override who / when / reason on sales orders). Re-runnable. No new seeders. **Run the upgrade page as usual.**
- A customer's **Credit limit** is now enforced on charges to the account: admin *Confirm*, POS tender *Charge to account*, van sale *Charge account*, and B2B portal checkout (customers on account terms). Open AR = unpaid invoices + remaining beginning balance (a negative beginning balance is a credit and reduces it) + confirmed / picked / delivered orders not yet invoiced, less amounts already paid or credited. An order that would go over the limit is refused with the numbers; exactly at the limit is allowed.
- **0 = no limit** (every existing customer had 0, which was never enforced). Set a limit on the customer to switch it on.
- Not blocked: sales paid at the time of sale (POS / van cash, check, card) and customers on COD / Cash / Prepaid / Paid / Due on receipt / CIA terms.
- Owners/admins: *Sales Orders > ⋮ > Confirm with credit override* (reason required) confirms the order anyway; the override (who, when, reason, limit, open AR) is in the Activity log.
- *Customers* (edit) and the sales order form show **Available credit**. The portal shows "This order ($x) is more than your available credit ($y). Please make a payment on the Pay page, reduce the order, or contact your sales rep." and saves nothing.
- *Settings > Users > New user*: **Active** now defaults on.
- MSA weekly pre-check message no longer shows a client distributor ID.

**17 (v0.17.0): Import tools.** Goes on top of 0.16.0 (or any earlier site; includes everything). New migration `2026_11_06_000001_phase17_imports` (import batches / rows / saved mappings; vendor item numbers; scheduled cost changes). Re-runnable; short key names. No new seeders. **Run the upgrade page as usual.** Cron must keep running `schedule:run` (it already does for the queue).
- *Settings > Imports* (owner/admin; delegate in `config/erp.php` `permissions.imports`): **customers, ship-tos, products (UPC, extra barcodes, up to 3 units of measure with their own barcodes, preferred vendor + vendor item #), vendors, opening inventory, price lists / contract prices, customer opening balances**. CSV (comma/semicolon/tab) or XLSX (first sheet), up to 100,000 rows, 50 MB.
- Steps: upload → **column mapping** (auto-matched by header; save/load named mapping templates) → **Dry run** checks every row and shows new / changed (old → new) / unchanged / errors without writing anything → **Commit** imports the valid rows (rows with errors are skipped and listed). Matching: customer number, ship-to (customer + name), SKU, vendor code, product+warehouse, product+customer/group+min qty+start date. Blank cells never erase existing data. **Download error report** (original columns + problem) and **blank CSV/XLSX templates** per type.
- Big files run in 250-row chunks: the page advances them while open, and the cron queue worker continues if you leave.
- Opening inventory sets on-hand to the file quantity (moves the difference) and posts one entry Dr 1200 Inventory / Cr 3900 Opening equity; lot-tracked products are refused (use receiving). Opening balances use the existing beginning-balance posting (Dr 1100 / Cr 3900) and audit.
- **Vendor price file** (same page, choose vendor + effective date): per-vendor saved mapping, match by vendor item # then UPC, review old cost → new cost and % change, unmatched items flagged (in the error report). Effective today/blank = cost updated on commit; a future date schedules the change (applied nightly at 00:05 by `costs:apply`, or with *Apply due cost changes*). Matching by UPC also remembers the vendor item # for next time. Price files never change selling prices and never create products. *Vendors > (vendor) > Vendor items* tab lists product, SKU, UPC, vendor item #, last cost and pending scheduled cost changes (owners/admins can add, edit, delete).
- Every import (upload, commit, totals) and every record it creates or changes is in the Activity log.

**16 (v0.16.0): Admin password reset + full change history.** Goes on top of 0.15.0 (or any earlier site; includes everything). New migration `2026_11_05_000001_phase16_security_audit` (append-only `activity_logs` table with 4 short-named indexes; `must_change_password` / `password_changed_at` on users). Re-runnable. No new seeders. **Run the upgrade page as usual.** No two-factor.
- *Settings > Users*: **Reset password** (owner/admin; only an owner can reset an owner) sets a temporary password (pre-filled random 16 chars, min 12) and signs the user out of other sessions, or emails a reset link when mail is configured. **Must change password at next sign-in** (on by default) sends the user to *Change password* until they pick a new one. Everyone has *Change password* in the user menu.
- **Forgot password?** appears on the admin login only when mail is configured (`MAIL_MAILER` not log/array and a from address). If the site uses the log mailer it stays hidden; use Reset password instead.
- **Activity log** (*Admin > Activity log*, owner/admin only, read-only): every create/update/delete on every business model (who, when, IP, browser, old → new values), sign-in, sign-out, failed sign-in (email only), password reset/change, role change, settings change, and key actions recorded as their status change (confirmed, picked, delivered, invoiced, cancelled, posted, void, approved, filed, closed ...), journal posted, signature captured, price override, payment. Filters: user, record type, record ID, action, date range. Detail view shows the field-by-field old/new diff. Passwords, PIN hashes, tokens, API/transaction keys and secret settings are shown as ********. Entries cannot be edited or deleted (UI and model).
- **History** tab on sales orders, customers, products, purchase orders, vendors, vendor bills, payments, credit memos, tax filings, warehouses, delivery manifests, journals, counts, price rules, promotions and users.
- Not captured: bulk SQL updates that bypass the model layer (e.g. stock quantity counters, stop re-sequencing); the business document they belong to is still logged.

**15 (v0.15.0): Delivery, driver app, signatures, van sales, Modules launcher.** Goes on top of 0.14.1 (or any earlier site; includes everything). New migration `2026_11_04_000001_phase15_delivery_driver` (delivery days on customers and ship-tos; route name / sequence on ship-tos; van flag + van driver on warehouses; route name on manifests; notes, photo, GPS on stops; new `delivery_signatures` table; user landing page). Re-runnable; key names max 22 chars. No new seeders. **Run the upgrade page as usual.** Driver/settlement and cash collection settlement are intentionally not included.
- **Delivery days / route planning:** *Customers* and *Ship-tos* have delivery-day checkboxes (ship-to overrides customer) and ship-tos have Route name + Route sequence. On *Sales > Delivery manifests*, **Plan route** adds every confirmed/picked order for that warehouse whose delivery day matches the manifest date (optionally the manifest's route only, or ignoring days), ordered by route and sequence, skipping orders already on a stop.
- **Driver phone screen** at `/driver` (driver role; sees only own manifests): today's stops in order, map link, call link, order lines; mark **Delivered / Partial** (enter delivered qtys; the rest is released back to stock) **/ Refused** (status only; office re-delivers or cancels, no credit memo); optional photo proof (camera), notes, GPS when the phone allows.
- **Signature for regulated products:** any delivery, will-call pickup (*Sales order > Pickup (signature)*), counter POS sale or van sale containing tobacco, vapor, hemp or kratom (every tax class except General and Beverage) needs a finger signature, typed printed name and checked statements: "I warrant that I am 21 years of age or older." and, when charged to the account (terms other than COD/Cash/Prepaid, or POS/van tender "Charge account"): "I acknowledge this charge to the account of [customer] for [total] and I warrant that I am authorized to make this purchase on its behalf." Stored: image, name, exact text, time, staff user, IP, GPS. Shown on the order, on the invoice PDF and on the new **Delivery receipt PDF**. Delivery is blocked without it (the admin Deliver button now refuses regulated orders; use Pickup (signature) or the driver screen).
- **Van sales** at `/driver/van`: mark a warehouse as *Van / truck* (optionally assign a driver), load it with a stock transfer/adjustment, then sell on route: engine pricing (no driver price overrides), compliance check, KY excise and buydowns as on any invoice, signature, tender cash / check / charge account.
- **Modules launcher** (*Modules*, top of the menu): large icon tiles for every module the user's role can open. **Make Modules my home page** sends that user there after sign-in.

**14.1 (v0.14.1): Rebate accounting, promotion stacking, POS PIN login and tablet POS.** Goes on top of 0.14.0 (or any earlier site; includes everything). New migration `2026_11_03_000002_phase141_rebate_gl` (accounts 1250 / 5050; buydown accrual line type, credit memo link, journal links, paid date, `dedupe_key` unique key replacing the 0.14.0 one; user POS PIN hash + unique lookup; POS PIN log). New idempotent seeder **Phase14Seeder** (the two accounts; posts GL once for accruals made on 0.14.0). Re-runnable over a half-applied database; key names max 22 chars. **Not a files-only patch:** run the upgrade page as usual (migration + seeder).
- Promotions now **stack** on the best base price (contract, group, qty break, dated or list): source shows e.g. "Contract price + promo".
- Rebates/buydowns post to the GL when earned (Dr 1250 / Cr 5050 contra-COGS), reverse proportionally on credit memos (negative lines on the claim report, Dr 5050 / Cr 1250), and clear with **Record manufacturer payment** on the claim report (Dr Cash / Cr 1250; short/over pay to 5050). Details and the manual AP-offset step: `laravel/docs/REBATE_ACCOUNTING.md` (⚠ CPA to confirm contra-COGS treatment).
- **POS PIN quick login:** each employee can have a unique 4-digit PIN (admin: *Settings > Users > Set POS PIN / Clear POS PIN*; employee: **My PIN** on the POS, needs their password). PINs are stored hashed. A tablet becomes a POS terminal the first time someone opens `/pos` with their password; after that `/pos/lock` shows employee name tiles and a keypad. A PIN opens **only the POS** (never `/admin`). 5 wrong PINs lock that employee for 5 minutes (30 per terminal per 5 minutes). Every PIN login, wrong PIN, lockout and PIN change is in *Settings > POS PIN log*.
- **Idle lock:** 10 minutes without a tap/keypress (or waking the tablet after that) returns the POS to the lock screen; the in-progress cart, customer and tender are kept for the next login on that terminal. The **Lock / switch** button does the same at once. Note: this also ends a password session on that device.
- **Tablet POS:** portrait stacks; landscape (1000px+) shows cart | customer + tender side by side. 44px+ touch targets (48-72px), large scan box, − / + qty steppers, tender buttons, cash keypad with change due. Screenshots (768x1024, 1024x768): `docs/pos-screenshots/` in the source repo.

**14 (v0.14.0): Pricing.** Includes phases 8-13.1. New migration `2026_11_03_000001_phase14_pricing` (customer price groups; price rules; promotions; price source / quoted price / override flag on order lines; price override log; buydown type, vendor and program code; buydown accruals). Re-runnable over a half-applied database; all new key names are short (max 22 chars). No new seeders. Nothing changes for existing orders: old lines keep their price and show no source.
- **One pricing engine** for admin sales orders, POS and the portal (it replaces the plain list price). For each line it takes the best matching *Pricing > Price lists* row (customer contract > price group > all customers; then the highest minimum qty reached; then the latest effective date), inside its effective/expiry dates, otherwise the list price. Active *Pricing > Promotions* (date-bound % off or $ off per unit, by product, department, customer and/or price group; blank = any) are applied on top and the lowest result wins. Contract and group prices are net: a promotion only wins if list-minus-promo is lower.
- *Customers*: new **Price group** field. *Pricing > Price groups* manages the groups.
- Each order line stores its **price source** (List, Dated, Qty break, Group, Contract, + promo, Manual override), the list price and the quoted price. The admin order form refills the price when product or qty changes and shows the source under the price. Draft lines follow qty breaks; confirmed orders keep their prices.
- **Manual override still allowed** (admin order form and POS price box; the portal never). A price different from the quote is saved as *Manual override* and logged with who, when, channel, quoted price and source: *Pricing > Price overrides* (admin).
- *Catalog > Buydowns*: type (buydown or manufacturer rebate), claim-from vendor, program code. On invoicing, each line earns every active program for its product (capped at max units) and is recorded once per line. *Pricing > Rebate & buydown claims* (admin): filter by manufacturer, dates and claimed/unclaimed, totals, **Download claim CSV**, **Mark claimed** with a claim reference. Screen only, no printable, so no footer change.

**13.1 (v0.13.1) HOTFIX:** MySQL error 1059 in `2026_10_31_000001_phase11_counts_reorder` (count-line unique key name was 65 chars). That key is now named `inv_count_lines_count_product_lot_unique`, and the phase 10-13 migrations are re-runnable over a half-applied database (every table, column and index is checked first). Re-run the same upgrade page; it picks up where the failed run stopped.

**13 (v0.13.0): KY return approval, amendments, drill-down, XSD-gated XML.** Includes phases 8-12. New migration
`2026_11_02_000001_phase13_return_approval` (return versions / approval / amendment reason / XML snapshot; count auto-approve flag; turns **PACT covered on** for existing cigarette, RYO, smokeless and vapor products). No new seeders.
- *Tax Reports > Tax filings*: **Approve (locks month)** (owner/admin) regenerates the return, validates the XML against the KY schema (refuses if invalid) and stores it. While the latest version is approved or filed, invoices (delivered orders and their lines), credit memos and stock movements dated in that month cannot change. **Mark filed** now needs an approved return.
- **Amend** (owner/admin, reason required) creates v2, v3 ... as a draft and reopens the month until the amendment is approved; the earlier version is kept and marked superseded. Amended XML carries `AmendedReturnIndicator` X with all schedule details (KY EDI guides, "Amended Returns"). Replaces the old owner "Reopen as draft".
- **Drill-down** from every return line (click the line number on the report, or the Drill-down action on a filing) to the invoices, credit memos and purchase orders behind it.
- **Download XML** is blocked when the XML does not validate (errors listed on the page). Cigarette uses the docs/xsd-cig set; tobacco uses docs/xsd V1.4 plus the documented local patch (V1.5 version, KY-VAP2, UNTAXED, 85-char names).
- Products: PACT covered defaults on for cigarette, RYO, smokeless and vapor tax classes (you can turn it off).
- Counts: a submitted count posts automatically when every line is within **$25 OR 2%** (Settings > Warehouse, editable); larger variances still need an owner/admin. Auto-approvals are logged in *Inventory > Short picks & over-receipts*.
- Reorder draft POs use the last PO unit price from that vendor, falling back to product cost.

**12 (v0.12.0): PACT Act reports, MSA state directories, carrier tracking.** Includes phases 8-11. New migration
`2026_11_01_000001_phase12_pact_directory_shipping` and new idempotent seeder **Phase12Seeder** (carriers UPS, FedEx, DHL, USPS (postal), Own delivery with public tracking links).
Requirements and citations: `laravel/docs/PACT_ACT_AND_MSA_DIRECTORY.md` (items marked ⚠ need CPA / attorney review).
- *Tax Reports > PACT registrations*: ATF (US) + per-state records (15 U.S.C. 376(a)(1)) with printable statement.
- *Tax Reports > PACT Act reports*: monthly per-state report (376(a)(2), due the 10th), by city and ZIP, with brand, qty, sticks, deliverer name/address/phone, tracking; HTML / PDF / CSV; Mark filed locks it.
- *Settings > PACT Act and MSA directories*: out-of-state PACT shipping stays **off** (old behaviour) until enabled, then needs current ATF + state registrations. Optional strict mode blocks cigarette/RYO into states with no directory loaded.
- *Tax Reports > MSA state directories*: CSV import per state with effective date; ComplianceChecker blocks non-listed cigarette / RYO brand families. Product field "MSA directory brand family".
- Cartons: carrier + tracking on the Pack screen or *Sales > Shipments*, tracking links, 4x6 label (no footer). USPS is blocked for tobacco / ENDS (18 U.S.C. 1716E). No carrier API.

**11 (v0.11.0): Inventory counts and reorder suggestions.** Includes phases 8-10. New migration `2026_10_31_000001_phase11_counts_reorder`
(counts, count lines, count scans; product preferred vendor / lead time / order multiple; vendor lead time) and new idempotent seeder
**Phase11Seeder** (account 5200 Inventory Shrinkage / Count Variance). The upgrade now runs TaxRateSeeder, Phase3Seeder, Phase4Seeder, Phase8Seeder, Phase11Seeder.
- *Inventory > Counts*: cycle (bins and/or items) or full (whole warehouse), optional blind. **Start** snapshots expected stock (per lot for lot-tracked items) and freezes the items (a full count freezes the warehouse): no receipts, sales or transfers move them until posted or cancelled.
- Scanner `/warehouse/count/{id}`: case/carton barcodes count their factor, lot entry (new lots found are created), set totals, undo, submit (optionally zero uncounted lines). Printable count sheet (no footer; blind hides expected).
- Owner/admin **Approve & post**: stock and lot adjustments plus one journal (gains Dr 1200 / Cr 5200, losses Dr 5200 / Cr 1200); can send back for recount or cancel (unfreezes, no change). Postings respect closed periods.
- *Inventory > Reorder suggestions*: reorder point, sales velocity, lead time (product > vendor > default) and on-order (draft/ordered/partial POs); quantity rounds up to the order multiple. One click creates a **draft** PO per vendor (admin); nothing is sent. Settings > Warehouse: velocity window, default lead time, days of cover.

**10 (v0.10.0): Lots, expiry and units of measure.** Includes phases 8 and 9. New migration `2026_10_30_000001_phase10_lots_uom`
(product units + extra barcodes, lots / lot stock / lot movements, FEFO pick allocations, PO over-receipt %, warehouse event log). No new seeders.
- Products: *Units, barcodes and lots* section (case > carton > pack factors, extra barcodes per unit, "Track lots + expiry"). A case/carton scan counts its factor.
- Lot-tracked items: receiving asks for lot + expiry (expired lots refused); picks are allocated first-expiring-first-out and the scanner enforces it; held/expired lots are never picked. Stock received before tracking was switched on ships as "unlotted".
- *Inventory > Lots*: expiry alerts (window in Settings, default 30 days), hold/release, **Recall look-up** (customers, ship-tos, orders, qty; CSV).
- *Inventory > Bins*: print bin barcode labels (all, per bin, or selected). No footer.
- Short pick button on the pick scanner: reduces the order line, needs an owner/admin (logged in, or enters their login at the scanner), reason required, logged.
- Over-receipt allowance: global % in *Settings > Warehouse* (default 0 = refuse) or per PO; every over-receipt is logged. Log: *Inventory > Short picks & over-receipts* (admin).

**9 (v0.9.0): Warehouse scanning.** Includes phase 8 (accounting) — a site still on 7.x gets both migrations. New migration
`2026_10_29_000001_phase9_warehouse_scanning` (bins, primary bin per stock row, receiving sessions/scans, put-away tasks, pick lists, cartons). No new seeders.

**8 (v0.8.0): Accounting.** Includes the 7.x hotfixes (7.1 footer, 7.2 dompdf public path + safe PDF logo). One new migration
`2026_10_28_000001_phase8_accounting` and a new idempotent seeder **Phase8Seeder** (accounts 2050 Received Not Billed, 5100 Purchase Price Variance,
6400 Bank Fees, 7000 Interest Income; an "Operating account" bank account on GL 1000 if none exists). The upgrade runs TaxRateSeeder, Phase3Seeder, Phase4Seeder, Phase8Seeder.

**7.1 (v0.7.1):** adds a "Powered by DistroSKU" footer to every page, printable, PDF, error page (403/404/500/503 etc.) and email template. No migrations. New files: `public_html/images/distrosku-logo.png` / `.webp`.

## Before you start
1. Site Tools > Site > MySQL > Backups (or phpMyAdmin export): back up the site database.
2. File Manager: download/back up `laravel/.env` (it holds the DB password and APP_KEY — never delete it).

## File Manager steps
1. Open `/home/customer/www/vegatest1.distrosku.com/`.
2. Create a folder `_upgrade` and upload `vega-erp-phase14.1-upgrade.zip` into it. Extract it there.
   You get `_upgrade/laravel/`, `_upgrade/public_html/`, and this README.
3. Move the contents of `_upgrade/laravel/` into the existing `laravel/` and choose **Overwrite**.
   The package has no `.env` and no `storage/`, so those stay as they are. Do NOT delete the old `laravel/` folder first.
   (Simplest: in `_upgrade/laravel/` select all → Move → `/home/customer/www/vegatest1.distrosku.com/laravel` → overwrite.)
4. Move the contents of `_upgrade/public_html/` into the existing `public_html/` (overwrite). Keep the existing `public_html/storage` link.
5. Visit `https://vegatest1.distrosku.com/upgrade-<TOKEN>.php?token=<TOKEN>` (exact filename is in `public_html/`), press **Run upgrade**.
   It: clears bootstrap caches → maintenance mode → `migrate --force` → runs ALL idempotent seeders: TaxRateSeeder, Phase3Seeder (accounts 3900/6300, install id), Phase4Seeder (company record; links existing products to the shared product master) Phase8Seeder (accounts 2050/5100/6400/7000, default bank account) Phase11Seeder (account 5200) and Phase12Seeder (default carriers) → creates `public_html/storage` link if missing →
   `optimize:clear`, `config:cache`, `route:cache`, `view:cache` → back up → deletes itself.
6. Delete the `_upgrade` folder.
7. Log in at `/admin`. Check *Tax Reports > Tax rates* — every KY rate is marked **needs verification**.

## If something fails
The page shows the log. The site is brought back up even if a step fails. Restore the DB backup if a migration failed half-way,
then send the log to BrandWorx. If the upgrade file did not delete itself, delete it in File Manager.

## After upgrading (one-time)
- Owner or BrandWorx admin: *Admin > Settings*. Enter the Authorize.net **sandbox** API Login ID, Transaction Key and Public Client Key
  (stored encrypted in the database). Without them the Card & ACH page shows "not configured". Keep `AUTHNET_ENV=sandbox`.
  The keys can also go in `laravel/.env` as AUTHNET_LOGIN_ID / AUTHNET_TRANSACTION_KEY / AUTHNET_CLIENT_KEY (Settings wins).
- Same page: KY filer details (FEIN, KY license, address, contact) used in the KY XML header; Rules Hub URL + public key.
- Rules Hub nightly sync needs the Laravel scheduler. SiteGround Site Tools > Devs > Cron Jobs, every minute:
  `cd /home/customer/www/vegatest1.distrosku.com/laravel && php artisan schedule:run >> /dev/null 2>&1`
- Products: fill *KY EDI* fields (manufacturer FEIN, MSA status, KY state desc override, EDI unit) for KY tobacco items.

## Self-deploy (new in this package): one-command deploys over HTTPS
- On the upgrade page, paste the **Deploy secret** (the box file `secrets/deploy_hmac_secret`) into the field before pressing *Run upgrade*.
  It is saved to `laravel/.deploy-secret` (mode 600, outside the web root). Without it, `deploy.php` answers 404 to everything.
- `public_html/deploy.php` (standalone, does not need a working Laravel): HMAC-signed POSTs only (5-minute window, single-use nonce),
  packages must carry an Ed25519 signature from the box key (public key in `laravel/config/deploy.php`). Chunked upload, maintenance mode,
  `laravel-prev/` backup (one copy), atomic folder swap keeping `.env` and `storage/`, migrations + seeders from the package manifest,
  caches, health check of `/up` and `/admin/login`, automatic rollback on failure. State lives in `deploy-work/` next to `laravel/`.
- From the box: `bash deploy.sh <zip> https://vegatest1.distrosku.com`, `bash deploy.sh status <url>`, `bash deploy.sh rollback <url>`.
- Rollback restores code and public files only; database migrations are not reversed.

## What's new (phase 9) - Warehouse scanning (warehouse role + owner/admin)
- Scanner screens at **`/warehouse`** (phone, tablet or rugged scanner; the scan box keeps focus, Enter submits; menu: Inventory > Warehouse scanner).
  Barcodes resolve by UPC (12/13-digit forms), MSA selling-unit UPC or SKU.
- **Receive** (`/warehouse/receive/{po}`, or *Scan receive* on a PO): scan items against the PO; wrong items and over-receipts are refused; undo last scan;
  *Post receipt* posts the scanned quantities through the normal receiving (stock + Dr 1200 / Cr 2050), records short lines as variances and creates put-away tasks.
- **Put-away** (`/warehouse/putaway`): scan the bin label, then the item. The first put-away sets the item's primary bin in that warehouse.
- **Bins** (`/admin/bins`): code (= barcode label), zone, pick sequence (pick-list walk order). Create bins before put-away.
- **Pick** (`/warehouse/pick/{order}`, or *Scan pick* on a confirmed order): lines in bin walk order; scan-to-confirm refuses wrong items and over-picks;
  *Complete pick* needs every line fully picked and marks the order Picked.
- **Pack** (`/warehouse/pack/{order}`): scan picked items into cartons, close cartons with an optional weight, complete packing.
- **Printables (no footer):** pick list `/warehouse/print/pick/{order}` and packing list `/warehouse/print/pack/{order}` (browser print, or `?format=pdf`).
  Also on the sales order menu (*Print pick list*, *Packing list*). Sales can print; drivers cannot.
- Delivery, invoicing and GL are unchanged: a picked/packed order is delivered and invoiced as before.

## What's new (phase 8) - Accounting (owner/admin/brandworx_admin only)
- **Change in posting:** PO receipts now post Dr 1200 Inventory / Cr **2050 Received Not Billed**; the vendor bill moves it to 2000 AP.
  Quantities received *before* this upgrade are recorded as "legacy" (they already credited AP), so billing them later does not double AP.
- **Vendor bills** (`/admin/vendor-bills`): *Create bill* on a received PO (Purchasing > Purchase orders) makes a draft for everything received and unbilled at PO cost;
  3-way match flags billed qty above received qty and cost differences above 0.5% (setting `ap_price_tolerance_pct`). Variances post only with an admin's approval
  (difference to 5100). Standalone bills (freight, expenses) need a GL account per line. Posted bills are voided, never edited.
- **Vendor payments** (`/admin/vendor-payments`, *Pay bills*): Dr 2000 / Cr the bank account's GL; allocate to one or more bills; void reverses.
- **AP aging** (`/admin/accounting/ap-aging`) by due date, with GL 2000 / 2050 balances in the notes.
- **Bank accounts + reconciliation** (`/admin/bank-accounts`, `/admin/accounting/bank-reconciliation`): import a CSV statement (Date, Description, Amount or Debit/Credit,
  optional Reference), auto-match by amount within 7 days, manual match/unmatch, post fees/interest as adjustments, finish when the difference is 0.00; CSV/PDF report.
  Set *cleared through* on the bank account to your last outside reconciliation so older GL entries don't show as outstanding.
- **Financial statements** (CSV/PDF, compare with prior period or same period last year): `/admin/accounting/trial-balance`, `/profit-loss`, `/balance-sheet`, `/cash-flow`.
- **Period close** (`/admin/accounting/periods`): closed months refuse every posting; admin reopen requires a reason; audit log on the page.
- **Dunning** (`/admin/accounting/dunning`): levels (default 1 / 30 / 60 days past due; due = invoice date + Net N terms), *Run dunning* makes a batch of **draft** notices and a
  statement-batch PDF. **Nothing is emailed** — download a notice as an unsent .eml draft and send it from your own mailbox.
- **Manual journal entry** (Accounting > Journals > *Manual entry*), admin only, balanced, open period only.

## What's new (phase 7) - MSA MULTICAT weekly file (.TOB)
- One new migration `2026_10_27_000001_phase7_msa_multicat`: MSA fields on `products` (category code, items per selling unit, MSA units per ERP unit,
  selling-unit UPC / description overrides, promo flag / code / description, shipper flag, mfr item #, unit size, exclude), on `ship_tos`
  (MSA shipping # / ext, chain store #, TDLinx, cash and carry, phone) and `customers` (class of trade override, promotion acceptance),
  plus the `msa_submissions` archive table. Seeders re-run (no new seed data).
- *Reports > MSA weekly (MULTICAT)* (`/admin/msa/weekly`, owner/admin/brandworx_admin only): MSA settings (distributor ID, address, contact,
  week end day - default **Saturday**, Test flag - default **T**, dollars, Altria STARS fields, tax jurisdiction, line ending), pre-check
  (errors block generation), preview of BID / SID / PUR records and raw lines, **Generate .TOB** (download + archived copy), history with download / mark uploaded.
  Nothing is sent to MSA: download the file and upload it to the MSA hub yourself (Test folder until MSA approves Live).
- *Reports > MSA missing data* (`/admin/msa/fix-list`): tobacco products missing MSA category / items per unit / UPC / promo description,
  with a bulk "apply suggested category + items" action (from tax class and sticks per unit).
- Layout: `docs/msa/MULTICAT_LAYOUT.md` (MSA MULTICAT Tobacco requirements, Apr 2019; Altria STARS package for optional return measures).
- First run: enter MSA settings (distributor ID 12345678), set MSA category + items per selling unit on tobacco products, MSA shipping numbers on ship-tos if needed.

## What's new (phase 6) - live dashboard KPIs + Reports
- One new migration: `customers.sales_rep_id`, `sales_orders.sales_rep_id` (new orders inherit the customer's rep), `products.reorder_point`. Seeders re-run (no new seed data).
- Dashboard (`/admin`): live tiles refreshing every 30 s (today / month-to-date net sales, open orders, AR total and past-due incl. beginning balances,
  KY excise accrued this month, low stock), 30/90-day sales trend, AR aging chart, top 5 customers and products this month.
  Owner/admin/brandworx_admin see everything; sales: sales, orders, AR, top lists, trend; warehouse: open orders, low stock, top products; driver: open orders.
- New *Reports* menu (`/admin/reports/...`): sales by customer / product / department / rep, gross margin, AR aging, customer statement (from the AR ledger,
  so beginning balances count), inventory valuation and movement, tax & excise summary (links to the KY reports), payments received.
  Each has date / customer / department filters and CSV + PDF export. Sales and inventory reports are open to the sales and warehouse roles respectively; the rest are admin-level.
- To use: set a *Sales rep* on customers, and a *Reorder point* on products for the low-stock tile.

## What's new (phase 5) - B2B customer portal (/portal)
- New migration: `customer_contacts` (portal logins, separate from staff users) and `payments.customer_contact_id`. Seeders re-run (no new seed data).
- *Customers > edit > Portal contacts* (owner/admin/brandworx_admin): add contacts (optional ship-to restriction), then **Set-password link**:
  a one-time link valid 72 h, shown once in the admin screen to copy. **Nothing is emailed.** Issuing a new link is also the password reset.
- Portal (mobile-first): catalog of active, non-archived products with the customer's price and compliance check for the chosen ship-to
  (unavailable items show why), cart, submit order (portal SalesOrder: confirmed, or draft when stock is short), reorder from past orders,
  orders & invoices, balance incl. beginning balance with aging, invoice PDF, statement PDF, pay an invoice or the full balance
  (Authorize.net SANDBOX via Accept.js; shows "not configured" when keys are missing).
- Admin: customer edit page shows Balance & aging and a **Statement PDF** button; sales orders have an **Invoice PDF** action.
- Account suspension blocks the portal. Login (5 tries/min per email+IP) and portal pages are rate-limited.
- No new public files: `.htaccess` unchanged (Accept.js loads from Authorize.net).

## What's new (phase 4.1) - product archive
- *Catalog > Products*: **Archive / Unarchive** (row, edit page and bulk) for owner/admin/brandworx_admin.
- **Delete** shows only for products with no sales, purchase, return or inventory history; otherwise archive them. Bulk delete skips those products.
- Archived products are hidden from the product list (filter **Show archived**), POS, the quick-tools lookup, SO/PO product pickers, catalog CSV/PDF and the global catalog.
  Existing orders, reports and research still show them. They can't be added to new orders.
- Admin product form: Active defaults ON, Cost optional (saved as 0).
- `public_html/.htaccess` hardened: denies php_errorlog, *.log, dotfiles, config/archive files and any .php other than index.php / upgrade-<token>.php. `public_html/vendor/html5-qrcode` is expected (bundled scanner JS).
- One new migration (`archived_at`, `archived_by` on products). Works on sites at phase 3 or 4 (all seeders re-run safely).

## Phase 4 setup (one-time)
- *Catalog > Departments*: add departments (code like ELEC). New SKUs get ELEC-0001, ELEC-0002...; existing SKUs are unchanged.
- *Admin > Branding & quick-tools link*: upload the logo, press **Generate link** and share the `/q/...` link with employees (no login).
  Regenerate it if it leaks. Uploaded images are stored under `laravel/storage/app/public` and served via `public_html/storage`.
- *Platform* (brandworx_admin only): company record, create users / reset passwords, suspend or reactivate the account, global catalog.

## What's new (phase 4)
- SKU catalog on the existing products: departments, generated SKU codes (= barcode), description, image (resized to max 400 px), 60x60 thumbnails.
- CODE128 labels (print view + PDF), catalog CSV and PDF export.
- Employee Quick Tools page `/q/<token>`: create SKU, lookup by code/name/UPC, print labels; wedge scanners + camera "Scan" (bundled html5-qrcode).
- Shared product research (avg/min/max price per normalized name).
- Company logo in the admin header and on the quick-tools page; account suspension.
- Customer list shows the Beginning balance column to admin-level roles.

## What's new (phase 3)
- Customer returns / credit memos against invoice lines (restock or damage); reversing AR/sales/COGS/inventory entries; KY reports use them.
- Card & eCheck (ACH) payments via Authorize.net SANDBOX (Accept.js tokens, CIM profiles), refund and void.
- Rules Hub: signed (Ed25519) rules/tax-rate package sync, nightly + "Sync now"; `php artisan rules:keygen` / `rules:build --sign=`.
- KY excise payment from a filed return (Dr 2100 / Cr Cash).
- KY XML (EDI) download on both KY report pages, validated against the KY schemas.
- Customer beginning balances (admin-level roles only), posted to 1100 / 3900 with an audit log.

## What's new (phase 2)
- Role permissions (owner/admin/brandworx_admin full; sales; warehouse; driver). Owner can deactivate the BrandWorx admin account.
- KY excise rates table; excise accrual (Cr 2100) when untaxed snuff/chew is received from a non-KY-licensed vendor.
- Tax Reports: KY Resident Subjobber cigarette return and KY Tobacco Distributor return (73A422) — preview, CSV, PDF, draft/filed records.
- Partial PO receiving, collision-free document numbers, manifest stop "delivered" delivers the order, journal reversal.
