<?php

namespace App\Msa;

/**
 * MSA MULTICAT (Tobacco) fixed-width record layouts, 1-based columns, exactly per docs/msa/MULTICAT_LAYOUT.md
 * (MSA "MULTICAT Format Requirements - Tobacco", Apr 2019, pp.17-26; Altria STARS package for the optional return measures).
 * Each field: [name, start column, length, justification].
 *   LB = left-justify / blank-fill, RB = right-justify / blank-fill, RZ = right-justify / zero-fill,
 *   N  = real number with floating decimal, right-justified zero-filled (sign first),
 *   NB = real number, right-justified blank-fill, D2 = fixed 2 decimals, right-justified zero-filled (sign first), F = full literal (blank-filled if empty).
 * Fields not listed are blank (reserved / N/A); the writer blank-fills every gap up to the record length.
 */
final class Layout
{
    public const HID_LENGTH = 337;

    public const HID = [
        ['type', 1, 3, 'F'], ['distributor_id', 4, 8, 'F'], ['project', 12, 4, 'LB'], ['test', 16, 1, 'F'], ['interval', 17, 1, 'F'],
        ['week_ending', 18, 8, 'F'], ['name', 26, 32, 'LB'], ['address', 58, 90, 'LB'], ['city', 148, 25, 'LB'], ['state', 173, 2, 'F'],
        ['zip', 175, 9, 'LB'], ['country', 184, 3, 'F'], ['contact_last', 187, 20, 'LB'], ['contact_first', 207, 20, 'LB'],
        ['phone', 232, 10, 'F'], ['fax', 247, 10, 'F'], ['email', 257, 60, 'LB'],
        ['bid_measures', 317, 4, 'RZ'], ['sid_measures', 321, 4, 'RZ'], ['pur_measures', 325, 4, 'RZ'],
        ['created', 329, 8, 'F'], ['inventory_resubmission', 337, 1, 'F'],
    ];

    public const BID_LENGTH = 261;

    public const BID_LENGTH_ALTRIA = 275;

    public const BID = [
        ['type', 1, 3, 'F'], ['upc', 4, 14, 'RB'], ['sku', 18, 14, 'RZ'], ['description', 32, 50, 'LB'], ['promo_description', 82, 50, 'LB'],
        ['items_per_unit', 132, 6, 'RZ'], ['promo_indicator', 138, 1, 'F'], ['nacs', 139, 6, 'F'], ['msa_category', 145, 6, 'F'],
        ['unit_size', 161, 6, 'RZ'], ['unit_size_desc', 167, 10, 'LB'], ['shipper_flag', 177, 1, 'F'], ['mfr_promo_code', 178, 10, 'LB'],
        ['mfr_product_code', 188, 14, 'LB'], ['tax_jurisdiction', 208, 2, 'F'], ['alt_upc1', 210, 16, 'RB'], ['alt_upc2', 226, 16, 'RB'],
        ['spiral_size', 242, 2, 'RZ'], ['m1_code', 248, 3, 'F'], ['m1_value', 251, 11, 'N'],
        // Altria STARS only: floor returns (to manufacturer), reported negative.
        ['m2_code', 262, 3, 'F'], ['m2_value', 265, 11, 'N'],
    ];

    public const SID_LENGTH = 551;

    public const SID = [
        ['type', 1, 3, 'F'], ['customer_number', 4, 8, 'LB'], ['shipping_number', 12, 8, 'LB'], ['shipping_ext', 20, 8, 'LB'],
        ['name', 28, 32, 'LB'], ['store_number', 60, 8, 'LB'], ['address', 68, 90, 'LB'], ['city', 158, 25, 'LB'], ['state', 183, 2, 'F'],
        ['zip', 185, 9, 'LB'], ['country', 194, 3, 'F'], ['tax_jurisdiction', 197, 2, 'F'], ['phone', 202, 10, 'F'],
        ['class_of_trade', 212, 20, 'LB'], ['tdlinx', 232, 7, 'F'], ['cash_and_carry', 239, 1, 'F'],
        ['bill_to_number', 259, 24, 'LB'], ['bill_to_name', 307, 32, 'LB'], ['bill_to_address', 339, 90, 'LB'], ['bill_to_city', 429, 25, 'LB'],
        ['bill_to_state', 454, 2, 'F'], ['bill_to_zip', 456, 9, 'LB'], ['bill_to_country', 465, 3, 'F'], ['bill_to_phone', 473, 10, 'F'],
        ['promo_acceptance', 498, 1, 'F'], ['sales_rep', 499, 10, 'LB'],
    ];

    public const PUR_LENGTH = 116;          // qty only

    public const PUR_LENGTH_DOLLARS = 130;  // + 002 dollars

    /** Fixed part; measures are appended by the writer (each 3-char code + 11-char value from column 103). */
    public const PUR = [
        ['type', 1, 3, 'F'], ['customer_number', 4, 8, 'LB'], ['shipping_number', 12, 8, 'LB'], ['shipping_ext', 20, 8, 'LB'],
        ['sku', 28, 14, 'RZ'], ['invoice', 45, 30, 'LB'], ['transaction_date', 75, 8, 'F'],
    ];

    public const PUR_MEASURES_START = 103;

    public const TOT_LENGTH = 140;

    public const TOT_LENGTH_ALTRIA = 194;

    public const TOT = [
        ['type', 1, 3, 'F'], ['distributor_id', 4, 8, 'F'], ['week_ending', 12, 8, 'F'], ['bid_count', 20, 9, 'RZ'], ['sid_count', 29, 9, 'RZ'],
        ['pur_count', 38, 9, 'RZ'], ['m1_code', 87, 3, 'F'], ['m1_value', 90, 15, 'N'], ['m2_code', 105, 3, 'F'], ['m2_value', 108, 15, 'NB'],
        ['m3_code', 123, 3, 'F'], ['m3_value', 126, 15, 'N'],
        // Altria STARS only (columns per its TOT table; codes per its BID/PUR tables - see "confirm with MSA").
        ['m4_code', 141, 3, 'F'], ['m4_value', 144, 15, 'N'], ['m5_code', 159, 3, 'F'], ['m5_value', 162, 15, 'N'],
        ['m6_code', 177, 3, 'F'], ['m6_value', 180, 15, 'N'],
    ];

    public const MEASURE_QTY = '001';

    public const MEASURE_DOLLARS = '002';

    public const MEASURE_INVENTORY = '003';

    public const MEASURE_SALEABLE_RETURNS = '004';

    public const MEASURE_UNSALEABLE_RETURNS = '005';

    public const MEASURE_FLOOR_RETURNS = '006';

    /** MSA category codes (MSA Tobacco requirements p.12; 003218/003232 from Altria STARS p.25). */
    public const CATEGORIES = [
        '003211' => 'Moist snuff / spit-free / hard snuff', '003212' => 'Loose leaf chewing tobacco', '003213' => 'Dry snuff (nasal)',
        '003214' => 'Twist / rope / plug', '003217' => 'Snus', '003218' => 'Hard snuff / compressed (Altria)', '003221' => 'RYO / MYO tobacco',
        '003227' => 'Premium cigars', '003231' => 'Cigarettes', '003232' => 'Heated tobacco (Altria)', '003241' => 'Pipe tobacco',
        '003251' => 'Large cigars', '003252' => 'Little / filtered cigars', '003261' => 'Papers / tubes / wraps',
        '003292' => 'E-cigarettes / e-vapor', '003293' => 'Tobacco-derived nicotine (pouches, gum, lozenges)',
    ];
}
