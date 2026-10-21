# KY EDI XML export: sources and mapping gaps

Sources (downloaded 2026-10-20 from revenue.ky.gov, copies in the build workspace `docs/`):
- Kentucky Tobacco Products EDI Guide v1.9 (07/01/2026), "XML Electronic Filing for Tobacco Products Data".
- KY tobacco schema KYTobacco2017V1.4 (public zip). The guide names V1.5 but the posted V1.5 zip link returns an error page,
  so `resources/ky-edi/KYTobacco2017V1.5-local` is V1.4 patched ONLY with the v1.5/1.8 changes in the guide's version history
  (stateSchemaVersion KYTobacco2017V1.5, KY-VAP2, UNTAXED, 85-char ProductDescription/Manufacturer/BrandFamily). Replace when KY posts V1.5.
- Kentucky Cigarette EDI Guide V1.7 (5-8-2023) + official schema KYCigarette2017V1.4 (used unchanged) for the subjobber return.

Generators: `App\Reports\KyTobaccoXml` (ReturnType TOB, root TobaccoTransmission) and `App\Reports\KyCigaretteXml`
(ReturnType CIG, root CigaretteTransmission). Every download is validated with DOMDocument::schemaValidate; problems are shown.

## Schedules produced
| Schedule (Tob. Table 5 / Cig. Table 4) | Source in ERP |
|---|---|
| 1A received from manufacturer | PO receipts, vendor `is_manufacturer` (tobacco only) |
| 1B received from other licensee | PO receipts, other vendors; credit memos from licensee customers |
| 1C received from retailer/end user | posted credit memos (returns) |
| 2B disbursed to licensee | KY deliveries to wholesaler/subjobber/distributor customers |
| 2C disbursed to retailer/end user | KY deliveries to all other customers |
| 1D, 2A, 2D | not produced (manufacturer-only, and returns to manufacturer are not modelled) |

## Fields we cannot fully map from ERP data
| Element | Guide section | What we do |
|---|---|---|
| Filer EIN/StateEIN/Name/USAddress, BusinessRepresentative | Tob. Table 1 ReturnHeaderState/Filer, BusinessRepresentative; Cig. Table 1 same | Settings > KY filer (must be entered) |
| Transmitter/StateEIN | Table 1 TransmissionHeader | = filer KY license |
| TransmissionId, SubmissionId | Table 1 TransmissionHeader / ReturnState | generated (VEGA+period+random) |
| ProcessType | Table 1 TransmissionHeader | P (production); T only for KY test filings |
| StateDesc dry snuff / snus / plug / twist (KY-DS1, SS1, PL*, TW*) | Tob. Table 3 | ERP only knows snuff vs chew: snuff -> KY-MS1, chew -> KY-LL1..4 by weight. Set "KY state desc" on the product to override |
| MRT codes (-MRT1/-MRT2) | Tob. Table 3 | only via product override |
| MSAStatus | Tob. Table 8 / Cig. Table 7 | product field; blank -> N/A |
| ManufacturerEIN | Tob./Cig. Table 1 Transactions | product field "Manufacturer FEIN"; missing -> 000000000 + warning (KY will reject) |
| Manufacturer, BrandFamily | Table 1 Transactions | product manufacturer / brand (falls back to brand / name) |
| UnitDescription | Tob. Table 10 | product "EDI unit", else from product unit (can/tin/pouch...), else ECH |
| Unit (snuff) | Tob. Table 12 | number of 1.5 oz increments per consumer unit |
| Value | Tob. Table 1/12 | line total / consumer units |
| TaxJurisdiction | Tob. Table 4 / Cig. Table 3 | always KY (only KY ship-tos are reported on the tobacco return) |
| Customer EIN / StateEIN | Table 1 TransactionDocument | customer FEIN / KY tobacco license, else 999999999 as the guide allows for end consumers |
| Address | Table 1 TransactionDocument/Address | ship-to (customers) or vendor address/city/zip; TypeOfAddress always "Location" (Table 11: only value KY uses) |
| DeliveryService | Table 1 | not produced (own trucks) |
| UPCUOM, SticksPerPack | Cig. Table 1 / Table 2 | from sticks_per_unit: >=1000 CSE, >=200 CAR, >1 PAK; SticksPerPack 20 assumed for cartons |
| SchPack (inventory), SchUnaffixedStampReport | Cig. Table 1 | not produced (optional; stamp report is for stamping agents) |
| TaxDue/PenaltyDue/InterestDue | Table 1 ReturnHeaderState | not sent ("N", not used by KY) |
| BinaryAttachment, FinancialTransaction | Table 1 | not used by KY |
| Premium cigars KY-LCGR1 | Tob. v1.9 version history (HB 757) | Value*Quantity from 2026-07-01 |
