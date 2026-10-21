# Phase 12: PACT Act reports, MSA state directories, carrier tracking

Status: implementation notes for v0.12.0. **Not legal advice.** The items marked ⚠ need a CPA or attorney check before you rely on them.

## 1. PACT Act / Jenkins Act (15 U.S.C. §§ 375–378)

### Sources
- 15 U.S.C. § 375, definitions. "Cigarette" includes **roll-your-own tobacco** and **electronic nicotine delivery systems** and excludes cigars (§ 375(2)). Smokeless tobacco is defined in § 375(13). https://www.law.cornell.edu/uscode/text/15/375
- 15 U.S.C. § 376, reports to the state tobacco tax administrator. https://www.law.cornell.edu/uscode/text/15/376
  - **(a)(1) Registration.** Before shipping, the seller files a statement with the U.S. Attorney General (ATF) **and** with the tobacco tax administrator of each state and place shipped into. It gives the name, trade name, principal and other places of business with phone numbers, principal email, websites, and an in-state agent authorized to accept service (name, address and phone).
  - **(a)(2) Monthly report.** It is due **by the 10th of each month** to the administrator of each state shipped into. It is a memorandum or copy of the invoice for **each and every shipment** made in the previous month. It includes the recipient's name and address, the **brand** and **quantity**, and the **name, address and phone number of the person delivering**. It is **organized by city or town and by ZIP code**.
  - **(a)(3) Local and tribal copies.** Copies go to the local and tribal tax administrators and chief law enforcement officers that levy their own taxes.
  - **(c) Confidentiality.** Agencies must keep the information confidential.
- ATF administers registration on **ATF Form 5070.1**, with continuation sheet 5070.1A. It is filed once federally, and states may require their own forms. https://www.atf.gov/alcohol-tobacco/questions-and-answers · ATF PACT Act Information Guide: https://www.atf.gov/file/102101/download
- 18 U.S.C. § 1716E: cigarettes and smokeless tobacco are **nonmailable** through USPS, and ENDS were added by the Preventing Online Sales of E-Cigarettes to Children Act (2020). The ERP blocks any USPS-flagged carrier for these cartons.

### What the ERP does
| Requirement | Where |
|---|---|
| Registration record for ATF (`US`) and each state, plus a printable statement | Tax Reports > PACT registrations; `/compliance/pact/registration/{id}` |
| Out-of-state shipping allowed only with current ATF **and** state registrations | Settings toggle `pact_interstate_enabled` (default **off**, which keeps the old block); enforced in `ComplianceChecker` |
| Monthly per-state report with recipient, license, address, city, ZIP, date, invoice, category (cigarette, RYO, smokeless, ENDS, other covered), brand, description, quantity, sticks or units, deliverer name/address/phone, tracking; sorted by city then ZIP; due date = 10th of the next month | Tax Reports > PACT Act reports; HTML, PDF (landscape, no footer), CSV |
| Filing record (reference) that locks the report | "Mark filed" (admin) |
| Name, address and phone of the person delivering | Carriers (Settings > Carriers), taken from the carton's carrier; "Own delivery" when no carrier is set |

### ⚠ Needs a CPA or attorney check
1. **Which shipments are covered.** The report includes every delivered order into a state other than the home state (`ERP_HOME_STATE`, default KY) that carries a covered item. Since 2010, § 376(a) applies to sales to licensed distributors and retailers as well as consumers (the "other than a distributor licensed by" exemption was removed). Confirm this with counsel.
2. **Product categories.** Cigarettes = tax class `cigarette_stamped`, RYO = `ryo`, smokeless = `snuff_chew`, ENDS = `vapor`, plus anything flagged "PACT covered". Pipe tobacco and cigars are **not** included unless flagged. Check how ENDS and pipe tobacco are classified in each state.
3. **Quantity unit.** The report shows the selling unit plus sticks or units (quantity × MSA items per unit). Some states want sticks, packs or cartons, or weight for RYO and smokeless.
4. **State-specific forms and portals.** Many states require their own format or e-filing, local copies (§ 376(a)(3)), and "no shipments" reports. The CSV is a working file, not a state form.
5. **Delivery-sale rules (§ 376a).** These cover sales to consumers: age verification, labeling, collecting tax in advance, and the delivery-seller registration list. They are **not** implemented, because this ERP is B2B. Do not use it for consumer delivery sales without counsel.
6. **Returns and credits** are not netted out of the monthly report.
7. **Common carriers.** UPS, FedEx and DHL have their own tobacco and vape shipping policies and contracts. Check them before using them for covered products.

## 2. MSA state tobacco product manufacturer directories

Every MSA state (and the NPM escrow states) publishes a **directory** of tobacco product manufacturers and **brand families** of cigarettes and RYO that may be stamped and sold in the state. Sales of brands not on the directory are prohibited under each state's "complementary legislation" (for Kentucky, see KRS 131.600 to 131.630 and the Attorney General’s directory; ⚠ confirm the exact citation). Most states publish the list as a web page or PDF that is updated often.

### What the ERP does
- Tax Reports > MSA state directories > **Import state list (CSV)**: give the state, effective date, source (URL and publication date) and a CSV with the columns `manufacturer, brand_family, product_type (cigarette|ryo|both)`, plus optional `effective_from` and `effective_to`.
- Each import is the **complete list** for that state from its effective date. The previous list is end-dated the day before, and history is kept, so orders are checked against the list in force on the order date.
- `ComplianceChecker` blocks cigarette and RYO lines into a state whose list is loaded when the product's **MSA brand family** (or its Brand, if that's blank) is not listed for that product type. Matching ignores case, spaces and punctuation.
- If no list is loaded for a state, nothing is blocked unless the Settings toggle `msa_directory_strict` is on.

### ⚠ Needs an attorney check
1. Brand-family matching is by name. Directory wording ("Marlboro" vs "Marlboro (all styles)") needs a person to check the product's MSA brand family field.
2. Some states also list **RYO** separately or by manufacturer only, or allow sell-off periods after a delisting. Delisting dates should be entered as `effective_to`.
3. Whether non-MSA states or tribal sales need any handling.

## 3. Carrier labels and tracking
- Carriers come from Settings > Carriers: UPS, FedEx, DHL, USPS (flagged postal) and Own delivery are seeded. Each has a public tracking-link template. There is no carrier API and no account credentials, and nothing is sent to carriers.
- Carriers and tracking numbers are entered on the Pack screen when closing a carton, or in Sales > Shipments. The 4×6 label (HTML or PDF) has ship-from, ship-to, carrier and service, carton n of N, weight, a tracking-number barcode and an order/carton barcode, and **no footer**.
- The label is **not** a carrier-certified label. Real UPS or FedEx labels still come from the carrier's system, unless a sandboxed API is added later.
