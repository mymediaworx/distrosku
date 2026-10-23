# MSA MULTICAT .TOB — format as observed in Vega's real weekly file (week ending 2026-10-18)

Derived from the distributor-supplied sample (kept locally in `msa-sample/`, git-ignored; never commit it).
Cross-checked with MSA "MULTICAT Format Requirements – Tobacco" (Apr 2019) — see `docs/msa/MULTICAT_LAYOUT.md`.
All values below are placeholders.

## File
- 7-bit printable ASCII, no BOM, no tabs. Fixed width, one record per line.
- Line ending CRLF on every line, including the last.
- Name: `YYYYMMDD.TOB` (week-ending date) — unconfirmed by MSA.
- Record order: `HID`, all `BID`, then for each ship-to: its `SID` immediately followed by its `PUR` lines, then `TOT`.
  (SID/PUR interleaved per customer, ordered by customer #.)
- Lengths (excluding CRLF): HID 337, BID 261, SID 551, PUR 130, TOT 140.

## Numbers
- Measure values: fixed 2 decimals with an explicit point, right-justified, ZERO-filled, sign first:
  `00000084.00`, `-0000060.00` (11 wide); TOT sums 15 wide `000000012345.67`.
  Applies to quantities, dollars and inventory alike (dollars zero-filled too, not blank-filled).
- Counts / codes: right-justified zero-filled (`000050`, `000001424`).
- Dates `YYYYMMDD`.

## HID (1)
| Cols | Field | Sample |
|---|---|---|
|1-3|`HID`| |
|4-11|Distributor ID|`NNNNNNNN`|
|12-15|`TOB ` | |
|16|Test flag (blank = live, `T` = test)|blank|
|17|`W`| |
|18-25|Week ending|`20261018`|
|26-57 / 58-147 / 148-172|Name / address / city (LJ/BF)| |
|173-174 / 175-183 / 184-186|State / ZIP (5 digits + 4 blanks) / `USA`| |
|187-206 / 207-226|Contact last / first| |
|232-241 / 247-256|Phone / fax (10 digits)| |
|257-316|Email (blank in sample)| |
|317-320 / 321-324 / 325-328|`0001` / `0000` / `0002` measure counts| |
|329-336|Created date (day after week end)|`20261019`|
|337|Inventory resubmission|blank|

## BID (one per reportable SKU, including SKUs with inventory but no sales)
|Cols|Field|Fmt|
|---|---|---|
|4-17|UPC, digits only, leading zeros/check digit as stored, RJ/BF|`   70000000000`|
|18-31|Distributor SKU, RJ/ZF|`00000000000004`|
|32-81|Description LJ/BF|
|82-131|Promo description (blank)|
|132-137|Items per selling unit RJ/ZF|`000050`|
|138|Promo flag `N`|
|145-150|MSA category|`003251`|
|248-250 / 251-261|`003` / on-hand selling units|`00000084.00`|
All other columns blank.

## SID (one per ship-to with sales)
|Cols|Field|Fmt|
|---|---|---|
|4-11|Customer # RJ/ZF (8)|`00000013`|
|12-19|Shipping # RJ/ZF (8; same as customer # in sample)|`00000013`|
|28-59|Banner name|
|68-157 / 158-182 / 183-184 / 185-193|Street / city / state / ZIP(5)+blanks|
|212-231|Class of trade: `Retail` or `Distributor`|
|498|Promo acceptance `Y`|
Country (194-196), phone, TDLinx, cash & carry, bill-to block: all blank in sample.

## PUR (one per ship-to × SKU; may repeat for same pair)
|Cols|Field|Fmt|
|---|---|---|
|4-11 / 12-19|Customer # / shipping # RJ/ZF|
|28-41|SKU RJ/ZF|
|45-74 / 75-82|Invoice # / date — blank in sample|
|103-105 / 106-116|`001` / qty (returns negative)|`00000002.00`|
|117-119 / 120-130|`002` / dollars, zero-filled|`00000015.78`|

## TOT
|Cols|Field|
|---|---|
|4-11 / 12-19|Distributor ID / week ending|
|20-28 / 29-37 / 38-46|# BID / # SID / # PUR (RJ/ZF 9)|
|47-86|blank|
|87-89 / 90-104|`001` / sum PUR qty (15, ZF, 2 dp)|
|105-107 / 108-122|`002` / sum PUR dollars (15, ZF, 2 dp)|
|123-125 / 126-140|`003` / sum BID inventory (15, ZF, 2 dp)|

## Validation rules worth enforcing
- TOT counts and sums must equal the detail records (sum the already-rounded 2-dp values).
- UPC: 12/13/14 digits with valid GS1 check digit (11-digit = UPC-A with leading 0 suppressed is accepted by the sample); never `0`.
- State uppercase; ZIP 5 or 9 digits; no PO boxes; every PUR has a matching SID and BID.
