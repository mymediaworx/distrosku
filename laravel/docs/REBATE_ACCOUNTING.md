# Manufacturer rebates and buydowns: accounting (0.14.1)

Accounts (added by migration `2026_11_03_000002_phase141_rebate_gl` and `Phase14Seeder`, both idempotent):
- **1250 Manufacturer Rebates Receivable** (asset): money the manufacturers owe Vega.
- **5050 Manufacturer Rebates & Buydowns (contra-COGS)** (expense type, normally a credit balance). It sits in the COGS section of the P&L, so it lowers cost of goods sold (a buydown is a reduction of product cost, not sales revenue). ⚠ CPA to confirm the treatment (contra-COGS vs other income) for Vega's books and taxes.

| Event | Entry | Where |
|---|---|---|
| Order invoiced; a line earns an active program (capped at max units) | Dr 1250 / Cr 5050, one journal per order, memo "Rebates/buydowns earned on SO…", source = the sales order | `RebateLedger::accrue` |
| Credit memo posted against that line | Reversal row (negative qty/amount) = earned qty × returned qty ÷ line qty, capped at what is left; Dr 5050 / Cr 1250, source = the credit memo; program units sold go down | `RebateLedger::reverseForCreditMemo` |
| Claim sent to manufacturer | *Pricing > Rebate & buydown claims*: filter unclaimed, **Download claim CSV**, **Mark claimed** (reference). No GL entry. Reversal rows appear as negative lines and net into the claim. | |
| Manufacturer pays | Select the claimed lines > **Record manufacturer payment** (date, amount received, reference): Dr 1000 Cash (received) / Cr 1250 (net claimed); a short payment is Dr 5050, an over-payment Cr 5050. Lines are marked paid. | `RebateLedger::recordPayment` |

Manual step: if the manufacturer pays by **credit on a vendor bill / deduction from an AP invoice** instead of a check, do not use Record payment. Post a manual journal (*Accounting > Journals*): Dr 2000 Accounts Payable / Cr 1250 for the amount, and mark the lines claimed. Payment always lands in 1000 Cash; move it to another bank account with a manual journal if needed.

Upgrading from 0.14.0: accruals made before 0.14.1 have no journal. `Phase14Seeder` (run by the upgrade page) posts them once, dated the invoice date (skipped and logged if that period is closed; then post manually).
