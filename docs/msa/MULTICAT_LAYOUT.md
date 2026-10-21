# MSA MULTICAT (Tobacco) — layout summary for Vega ERP

Sources (PDFs saved in this folder, text extracts alongside):
- [S1] MSA "MULTICAT Format Requirements – Tobacco", Apr 2019 — https://msa.avior.tax/sites/msa.avior.tax/files/Master_MultiCat_Format_Requirements_TOB.pdf (authoritative master; pp.17-26 hold the column tables)
- [S2] MSAi "All Tobacco Data Programming Detail" (ITG) — https://msa.avior.tax/sites/msa.avior.tax/files/MSAi_MultiCat_Format_Programming_Details.pdf
- [S3] Altria Tobacco Operating Cos "STARS Reporting Package" (Exhibit B) — https://msa.avior.tax/sites/msa.avior.tax/files/Altria_Tobacco_Operating_Co_STARS_Reporting_Package.pdf
- [S4] Avior mandatory fields — https://msa.avior.tax/doc/msa/msa-mandatory-data-fields
- [S5] Vizion guide — https://viziontools.com/resources/msa-reporting-for-tobacco-distributors/
Note: these are mirrored on a third-party vendor site (Avior), not msa.com. Confirm with MSA Distributor Support (877-544-4429 opt 1) that Apr-2019 is still current. Liggett RIS / blu / Hershey docs were NOT found publicly.

General [S1 p.3,16-17]: fixed-width ASCII, one record per line. Numeric measures = real number with floating decimal ("1.5", "-4.0"); assumed decimals rejected. LJ/BF = left-justify blank-fill, RJ/ZF = right-justify zero-fill. Order: HID, BID…, SID…, PUR…, TOT. Weekly; week-ending date (e.g. Saturday) [S1]; due Tuesday of following week [S5/S2]. File must be sent with Test flag "T" until MSA approves Live [S1 p.17].
File naming: "YYYYMMDD.TOB" is NOT defined in any of these docs — inferred from Vega's hub screenshot (20261018.TOB = week-end date + Project ID TOB). Confirm with MSA.

## HID (337 chars, 1 per file) [S1 p.17-18; S3]
| Cols | Len | Req | Field | Fmt |
|---|---|---|---|---|
|1-3|3|Y|"HID"|lit|
|4-11|8|Y|Distributor ID (MSA-assigned; e.g. 12345678)|full|
|12-15|4|Y|Project ID "TOB"|LJ/BF|
|16|1|Y|Test/Live: T or blank|
|17|1|Y|Interval "W"|
|18-25|8|Y|Week-ending date YYYYMMDD|
|26-57|32|Y|Distributor name|LJ/BF|
|58-147|90|Y|Address|LJ/BF|
|148-172|25|Y|City|LJ/BF|
|173-174|2|Y|State|
|175-183|9|Y|ZIP+4|LJ/BF|
|184-186|3|N|Country|
|187-206|20|Y|Contact last name|LJ/BF|
|207-226|20|Y|Contact first name|LJ/BF|
|227-231|5|N/A|Dialing code (reserved)|
|232-241|10|Y|Phone digits|
|242-246|5|N/A|reserved|
|247-256|10|Opt|Fax|
|257-316|60|Opt|Email|LJ/BF|
|317-320|4|Y|# measures on BID: 0001 (Altria w/ floor returns: 0002 [S3])|RJ/ZF|
|321-324|4|Y|# measures on SID: 0000|RJ/ZF|
|325-328|4|Y|# measures on PUR: 0001/0002 (Altria: up to 0004 [S3])|RJ/ZF|
|329-336|8|Y|File creation date|
|337|1|Y|Inventory resubmission flag: 1 or blank|

## BID (261 chars; 275 with Altria floor returns) — one per SKU sold/returned/inventoried [S1 p.19-21]
|Cols|Len|Req|Field|Fmt|
|---|---|---|---|---|
|1-3|3|Y|"BID"|
|4-17|14|Y|UPC of selling unit (carton/roll)|RJ/BF|
|18-31|14|Y|Distributor SKU (link key to PUR)|RJ/ZF|
|32-81|50|Y|Product description (brand + attributes, no state code/promo words)|LJ/BF|
|82-131|50|Y|Promotion description (blank if none)|LJ/BF|
|132-137|6|Y|Items per selling unit (sticks/cans/packs)|RJ/ZF|
|138|1|Y|Promotion indicator Y/N|
|139-144|6|Opt|NACS category code|
|145-150|6|Y|MSA category code (e.g. 003231)|
|151-160|10|N/A|blank|
|161-166|6|Opt|Consumer unit size|RJ/ZF|
|167-176|10|Opt|Unit size description|LJ/BF|
|177|1|Y|Component shipper flag S/C/blank|
|178-187|10|Y|Manufacturer promotion code (blank if none)|LJ/BF|
|188-201|14|Opt|Manufacturer product ID|LJ/BF|
|202-207|6|N/A|reserved|
|208-209|2|Opt|State tax jurisdiction (here OR SID, not both)|
|210-225|16|Opt|Alt UPC 1|RJ/BF|
|226-241|16|Opt|Alt UPC 2|RJ/BF|
|242-243|2|Opt|Spiral size|
|244-247|4|N/A|blank|
|248-250|3|Y|Measure code 1 = 003 (inventory)|
|251-261|11|Y|Closing inventory in selling units|RJ/ZF float|
|262-264|3|Altria|Measure code 2 = 006 floor returns (to mfr) [S3 p.~8]|
|265-275|11|Altria|Floor-return qty [S3]|

## SID (551 chars) — one per ship-to location [S1 p.21-23]
|Cols|Len|Req|Field|
|---|---|---|---|
|1-3|3|Y|"SID"|
|4-11|8|Y|Ship-to customer # (chain-shared) LJ/BF|
|12-19|8|Y|Ship-to shipping # (unique store) LJ/BF|
|20-27|8|Opt|Shipping # ext|
|28-59|32|Y|Banner name (storefront name; DBA as "Name DBA / Alt")|
|60-67|8|Opt|Chain store #|
|68-157|90|Y|Street address (no PO box)|
|158-182|25|Y|City|
|183-184|2|Y|State (USPS)|
|185-193|9|Y|ZIP+4 (blank last 4 if unknown)|
|194-196|3|Opt|Country (default USA)|
|197-198|2|Opt|State tax jurisdiction|
|199-201|3|N/A|blank|
|202-211|10|Opt|Phone|
|212-231|20|Y|Class of trade ("MSA Retailer"/"MSA Distributor"/"MSA Other" per Altria [S3])|
|232-238|7|Opt|TDLinx #|
|239|1|Opt|Cash & carry Y/N|
|240-258|19|N/A|reserved|
|259-282|24|Opt|Bill-to customer #|
|283-306|24|N/A|reserved|
|307-338|32|Opt|Bill-to name|
|339-428|90|Opt|Bill-to address|
|429-453|25|Opt|Bill-to city|
|454-455|2|Opt|Bill-to state|
|456-464|9|Opt|Bill-to ZIP+4|
|465-467|3|Opt|Bill-to country|
|468-472|5|N/A|blank|
|473-482|10|Opt|Bill-to phone|
|483-497|15|N/A|reserved sq/linear/running ft|
|498|1|Y|Promotion acceptance Y/N|
|499-508|10|Opt|Sales rep ID|
|509-537|29|N/A|blank|
|538-540|3|N/A|measure code (reserved)|
|541-551|11|N/A|measure value (reserved)|

## PUR — one per ship-to × SKU (Altria: × transaction date) [S1 p.24-25; S3]
|Cols|Len|Req|Field|
|---|---|---|---|
|1-3|3|Y|"PUR"|
|4-11|8|Y|Ship-to customer #|
|12-19|8|Y|Ship-to shipping #|
|20-27|8|Opt|Ext|
|28-41|14|Y|Distributor SKU RJ/ZF|
|42-44|3|N/A|blank|
|45-74|30|Opt|Invoice #|
|75-82|8|Opt (Altria Y)|Transaction/delivery date YYYYMMDD|
|83-102|20|N/A|blank|
|103-105|3|Y|001 qty shipped|
|106-116|11|Y|Selling units (returns negative, sign first, fractions e.g. -.5)|
|117-119|3|Opt|002 dollars|
|120-130|11|Opt|Dollars (RJ/BF)|
|131-133 / 145-147|3|Altria|004 saleable returns / 005 unsaleable returns [S3]; shift left to col 117 if no dollars|
|134-144 / 148-158|11|Altria|values|

## TOT (140 chars base) [S1 p.25-26]
|Cols|Len|Field|
|---|---|---|
|1-3|3|"TOT"|
|4-11|8|Distributor ID|
|12-19|8|Week-ending date|
|20-28|9|# BID RJ/ZF|
|29-37|9|# SID|
|38-46|9|# PUR|
|47-86|40|blank|
|87-89 / 90-104|3/15|001 / sum PUR qty|
|105-107 / 108-122|3/15|002 / sum dollars (opt)|
|123-125 / 126-140|3/15|003 / sum BID inventory|
Altria adds 141-194: floor/saleable/unsaleable return sums [S3]. CAUTION: Altria's TOT table numbers them 004/005/006 while its BID/PUR use 006/004/005 — inconsistent in source; confirm with MSA before Altria live.

## MSA category codes [S1 p.12]
003211 Moist snuff · 003212 Loose leaf · 003213 Dry snuff · 003214 Twist/rope · 003217 Snus · 003221 RYO/MYO · 003227 Premium cigars · 003231 Cigarettes · 003241 Pipe · 003251 Large cigars · 003252 Little/filtered cigars · 003261 Papers/tubes/wraps · 003292 E-cig · 003293 TDP/nicotine pouches/gum

## Selling-unit rules [S1 p.8-11,14-15]
Items/selling unit × PUR qty = MSA eaches. Carton=200, pack=20, 5-can roll=5, 10-can sleeve=10, 45-can display=45, 3-pack=60. Partial units as decimals (2 cans of a 10-sleeve = 0.2). Returns = separate PUR line, negative. Non-saleable returns not at SKU level → opposing return SKU whose description contains "returns". Shippers: report either the shipper (S) or its components (C), never both.
