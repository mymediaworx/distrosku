# Vega ERP: plan for 0.20.21 and later

From Michael's 2026-10-28 walkthrough. 0.20.20 shipped items 1, 5, 8, 10, 11, 12 and the first part of 9 (negative-inventory setting + quick counts). These are the rest. Order is a suggestion: smallest and safest first.

**Test note for every release:** the box has no PHP `intl` or `sodium` extensions, so the **existing test failures (73 as first counted; 72 in the final 0.20.20 run, an identical set on a clean 0.20.19 checkout) are environment failures, not regressions.** Almost all are Filament table/page views calling `Number::format` / `Number::currency` (needs `intl`); two are `sodium_crypto_sign_keypair()` in the Rules Hub tests (needs `sodium`). Compare a new run against the clean-baseline failure list, not against zero, or run the suite on a host that has both extensions. Run the suite with `php -d memory_limit=-1 vendor/bin/phpunit` (`artisan test` starts a child PHP that ignores `-d`).

> **Release numbering note:** 0.20.21 shipped the walk-in / cash rule (no regulated products for walk-in customers). The items below under this heading move to the next release (0.20.22).

## 0.20.22 (small, mostly Warehouse; was planned as 0.20.21)

### 2. Receiving without a PO (Warehouse)
- New *Receive stock* action under Warehouse that does not need a purchase order: pick vendor (optional), warehouse/bin, products, qty, unit cost, note.
- Posts through the existing `InventoryService::move(..., 'receipt')` and the PostingService receipt journal (Inventory debit, a clearing "Received not invoiced" credit, so a later vendor bill can match it). Reuse lot/GS1 capture from 0.20.15.
- Optional "attach to a PO later" link so Accounting can match the bill.
- Tests: stock and journal post, lot-tracked product asks for a lot, cannot receive negative, permission (warehouse role).

### 4. Bin photos
- `bin_photos` table (bin_id, path, taken_by, note). Upload from the bin page and from the phone (Quick Tools style, camera input), resized like product images (`ImageService`).
- Show the photo on the pick list and on the bin label page. Delete = admin only.
- Tests: upload resize, type/size limits, shows on pick list.

### 3. Consignment products
- Product flag `consignment` plus consignor (vendor). Stock is received at zero cost and tracked in a "consigned" quantity bucket per warehouse.
- On sale: Inventory/COGS are not touched; instead accrue a payable to the consignor (cost per unit or % of price) using a new account "Consignment payable". A monthly consignor statement report.
- Open questions for Michael: pay on sale or on a schedule; unsold stock returns; who owns shrink.
- Tests: sale accrues payable, no inventory asset, report totals, returning stock.

## 0.20.22 (Delivery / Shipping)

### 6. Delivery / Shipping heading + Carriers
- Rename the nav group to **Delivery / Shipping**; keep Delivery runs and Shipping labels in it and add **Carriers**.
- Carriers = UPS, FedEx, DHL, Freight (LTL) accounts: account number, credentials, enabled flag, default service, markup. Store credentials in encrypted settings (needs `sodium`, already required in production) and never in code, docs or zips.
- Per carrier API adapter behind one interface (`rate`, `label`, `track`, `void`). Start with rate + label + tracking for UPS and FedEx, then DHL, then a freight quote request form (no API) for Freight.
- Gate every live call behind a per-carrier "live" switch; default to sandbox. Today's `ShippingService` makes no live calls and a build check enforces that; the check is relaxed only for `app/Shipping/Carriers`.
- Tests: adapters against recorded fixtures (no network), credential never logged, rates shown on the order.
- Needs from Michael: carrier account numbers and API keys entered by him in Settings (not in chat).

## 0.20.23 (Driver app)

### 7. Driver app: product search, van setup, route mileage
- Replace the driver product box with the standard `ProductPicker` / `ProductSearch` (0.20.19) so search behaves like the office.
- **Van setup:** vans (name, plate, VIN, default warehouse = the van's stock location, assigned driver). Van sale and van stock use the van's location automatically.
- **Route start / end mileage:** driver enters odometer at start and end of a route (`route_mileage`: route, van, driver, start_odo, end_odo, started_at, ended_at). Validation: end >= start, start >= the van's last end. Totals on the route and a monthly mileage report (CSV).
- Tests: picker used, mileage rules, van location used in van sale.

## 0.20.24+ (bigger modules, each needs a short design sign-off first)

### 13. Employees: to-dos, follow-ups, performance, sales goals
- `tasks` (assignee, due date, related customer/order, status, source = manual / follow-up) with a "My day" dashboard widget and customer-page follow-ups.
- Sales goals per rep per month (revenue, orders, new accounts), progress bars on the dashboard, and a performance report (goal vs actual, calls/follow-ups done). Built from existing orders; no new data entry for actuals.
- Visibility: reps see only their own; managers see the team.

### 14. Time tracking
- Clock in / out (PIN on the tablet or phone), breaks, edits with a reason and an audit entry, weekly timesheet with manager approval, CSV export for payroll.
- Optional link to driver routes (mileage) and tasks. Overtime rules configurable. Decide first whether this feeds payroll or is record-only.

### 15. MCP server
- Read-only first: a token-protected MCP endpoint exposing safe tools (search customers/products, order status, stock on hand, invoices) using the same permission checks as the UI. Per-token role and a rate limit; an audit entry for every call.
- Writes (create order, adjust stock) only later, each behind an explicit scope and a confirmation step.
- Tokens are created in Settings and shown once; never stored in docs or zips.

## Carry-overs and known limits from 0.20.20
- Credit memos and returns on taxed lines do not refund the tax yet (the credit is price only). Fix with item 11 follow-up before anyone relies on taxes on returns.
- Portal orders also oversell when "allow negative inventory sales" is on (the setting is company-wide). Add a per-channel switch if Michael wants it off for the portal.
- KY excise is accrued on purchases only; it is not added to invoice prices. If Michael wants it shown as a tax line, set it as a tax on the product and lower the price to match.
