<?php

namespace Tests\Unit;

use App\Msa\Layout;
use App\Msa\MulticatBuilder;
use App\Msa\Writer;
use PHPUnit\Framework\TestCase;

/** Column positions per docs/msa/MULTICAT_LAYOUT.md, using the sample records printed in the MSA / Altria documents. */
class MulticatLayoutTest extends TestCase
{
    /** 1-based column slice. */
    private static function col(string $line, int $from, int $to): string
    {
        return substr($line, $from - 1, $to - $from + 1);
    }

    public function test_layout_fields_are_inside_records_and_do_not_overlap(): void
    {
        foreach ([[Layout::HID, Layout::HID_LENGTH], [Layout::BID, Layout::BID_LENGTH_ALTRIA], [Layout::SID, Layout::SID_LENGTH],
            [Layout::PUR, Layout::PUR_MEASURES_START - 1], [Layout::TOT, Layout::TOT_LENGTH_ALTRIA]] as [$layout, $len]) {
            $used = [];
            foreach ($layout as [$name, $start, $l]) {
                $this->assertLessThanOrEqual($len, $start + $l - 1, $name);
                for ($i = $start; $i < $start + $l; $i++) {
                    $this->assertArrayNotHasKey($i, $used, "$name overlaps ".($used[$i] ?? '')." at column $i");
                    $used[$i] = $name;
                }
            }
        }
    }

    public function test_hid_columns(): void
    {
        $l = Writer::record(Layout::HID, ['type' => 'HID', 'distributor_id' => '12345678', 'project' => 'TOB', 'test' => 'T', 'interval' => 'W',
            'week_ending' => '20261017', 'name' => 'VEGA DISTRIBUTION', 'address' => '1 MAIN ST', 'city' => 'LOUISVILLE', 'state' => 'KY',
            'zip' => '402020000', 'country' => 'USA', 'contact_last' => 'DOE', 'contact_first' => 'JANE', 'phone' => '5025551212',
            'email' => 'a@b.co', 'bid_measures' => '1', 'sid_measures' => '0', 'pur_measures' => '1', 'created' => '20261019'], Layout::HID_LENGTH);
        $this->assertSame(337, strlen($l));
        $this->assertSame('HID', self::col($l, 1, 3));
        $this->assertSame('12345678', self::col($l, 4, 11));
        $this->assertSame('TOB ', self::col($l, 12, 15));
        $this->assertSame('T', self::col($l, 16, 16));
        $this->assertSame('W', self::col($l, 17, 17));
        $this->assertSame('20261017', self::col($l, 18, 25));
        $this->assertSame(str_pad('VEGA DISTRIBUTION', 32), self::col($l, 26, 57));
        $this->assertSame(str_pad('1 MAIN ST', 90), self::col($l, 58, 147));
        $this->assertSame(str_pad('LOUISVILLE', 25), self::col($l, 148, 172));
        $this->assertSame('KY', self::col($l, 173, 174));
        $this->assertSame('402020000', self::col($l, 175, 183));
        $this->assertSame('USA', self::col($l, 184, 186));
        $this->assertSame(str_pad('DOE', 20), self::col($l, 187, 206));
        $this->assertSame(str_pad('JANE', 20), self::col($l, 207, 226));
        $this->assertSame('     ', self::col($l, 227, 231));
        $this->assertSame('5025551212', self::col($l, 232, 241));
        $this->assertSame(str_repeat(' ', 10), self::col($l, 247, 256));
        $this->assertSame(str_pad('a@b.co', 60), self::col($l, 257, 316));
        $this->assertSame('0001', self::col($l, 317, 320));
        $this->assertSame('0000', self::col($l, 321, 324));
        $this->assertSame('0001', self::col($l, 325, 328));
        $this->assertSame('20261019', self::col($l, 329, 336));
        $this->assertSame(' ', self::col($l, 337, 337)); // blank = do not process resubmitted inventory
    }

    /** Altria STARS p.24 sample: "BID 2820019720 00000013 MARLBORO VA BLEND KING BOX 000200 N 003231 00000000007". */
    public function test_bid_documented_sample_marlboro(): void
    {
        $l = Writer::record(Layout::BID, ['type' => 'BID', 'upc' => '2820019720', 'sku' => '00000013', 'description' => 'MARLBORO VA BLEND KING BOX',
            'items_per_unit' => '200', 'promo_indicator' => 'N', 'msa_category' => '003231', 'm1_code' => '003', 'm1_value' => '7'], Layout::BID_LENGTH);
        $this->assertSame(261, strlen($l));
        $this->assertSame('BID', self::col($l, 1, 3));
        $this->assertSame('    2820019720', self::col($l, 4, 17));      // UPC RJ/BF
        $this->assertSame('00000000000013', self::col($l, 18, 31));     // SKU RJ/ZF
        $this->assertSame(str_pad('MARLBORO VA BLEND KING BOX', 50), self::col($l, 32, 81));
        $this->assertSame(str_repeat(' ', 50), self::col($l, 82, 131)); // no promotion
        $this->assertSame('000200', self::col($l, 132, 137));
        $this->assertSame('N', self::col($l, 138, 138));
        $this->assertSame('003231', self::col($l, 145, 150));
        $this->assertSame(' ', self::col($l, 177, 177));
        $this->assertSame('003', self::col($l, 248, 250));
        $this->assertSame('00000000007', self::col($l, 251, 261));       // as printed in the sample
    }

    /** Altria STARS p.24: "BID 7310001461 00000003 COPENHAGEN LC NAT 5 CAN ROLL 000005 N 003211 00000000033". */
    public function test_bid_documented_sample_copenhagen(): void
    {
        $l = Writer::record(Layout::BID, ['type' => 'BID', 'upc' => '7310001461', 'sku' => '00000003', 'description' => 'COPENHAGEN LC NAT 5 CAN ROLL',
            'items_per_unit' => '5', 'promo_indicator' => 'N', 'msa_category' => '003211', 'm1_code' => '003', 'm1_value' => '33'], Layout::BID_LENGTH);
        $this->assertSame('000005', self::col($l, 132, 137));
        $this->assertSame('003211', self::col($l, 145, 150));
        $this->assertSame('00000000033', self::col($l, 251, 261));
    }

    /** Altria STARS p.25 promo sample: Black & Mild, promo Y "B&M B1T1 21900", code 21900, 003251, shipper S, inventory 250, floor returns -20. */
    public function test_bid_documented_sample_altria_promo_with_floor_returns(): void
    {
        $l = Writer::record(Layout::BID, ['type' => 'BID', 'upc' => '7013702208', 'sku' => '000000111111', 'description' => 'Black & Mild Plastic Tip Original',
            'promo_description' => 'B&M B1T1 21900', 'items_per_unit' => '50', 'promo_indicator' => 'Y', 'msa_category' => '003251', 'shipper_flag' => 'S',
            'mfr_promo_code' => '21900', 'm1_code' => '003', 'm1_value' => '250', 'm2_code' => '006', 'm2_value' => '-20'], Layout::BID_LENGTH_ALTRIA);
        $this->assertSame(275, strlen($l));
        $this->assertSame(str_pad('B&M B1T1 21900', 50), self::col($l, 82, 131));
        $this->assertSame('000050', self::col($l, 132, 137));
        $this->assertSame('Y', self::col($l, 138, 138));
        $this->assertSame('S', self::col($l, 177, 177));
        $this->assertSame(str_pad('21900', 10), self::col($l, 178, 187));
        $this->assertSame('00000000250', self::col($l, 251, 261));
        $this->assertSame('006', self::col($l, 262, 264));
        $this->assertSame('-0000000020', self::col($l, 265, 275));      // negative, sign first
    }

    /** MSA requirements p.6: tax jurisdiction option 1, "SID 12345678 23456789 34567891 ... PA" at 197-198. */
    public function test_sid_documented_sample_tax_jurisdiction(): void
    {
        $l = Writer::record(Layout::SID, ['type' => 'SID', 'customer_number' => '12345678', 'shipping_number' => '23456789', 'shipping_ext' => '34567891',
            'name' => 'ABC MART', 'store_number' => '123', 'address' => '100 ELM ST STE 2', 'city' => 'PITTSBURGH', 'state' => 'PA', 'zip' => '15201',
            'country' => 'USA', 'tax_jurisdiction' => 'PA', 'phone' => '4125551212', 'class_of_trade' => 'Retailer', 'tdlinx' => '1234567',
            'cash_and_carry' => 'N', 'bill_to_number' => '12345678', 'promo_acceptance' => 'Y', 'sales_rep' => '7'], Layout::SID_LENGTH);
        $this->assertSame(551, strlen($l));
        $this->assertSame('12345678', self::col($l, 4, 11));
        $this->assertSame('23456789', self::col($l, 12, 19));
        $this->assertSame('34567891', self::col($l, 20, 27));
        $this->assertSame(str_pad('ABC MART', 32), self::col($l, 28, 59));
        $this->assertSame('123     ', self::col($l, 60, 67));
        $this->assertSame(str_pad('100 ELM ST STE 2', 90), self::col($l, 68, 157));
        $this->assertSame(str_pad('PITTSBURGH', 25), self::col($l, 158, 182));
        $this->assertSame('PA', self::col($l, 183, 184));
        $this->assertSame('15201    ', self::col($l, 185, 193)); // +4 unknown: last 4 blank
        $this->assertSame('USA', self::col($l, 194, 196));
        $this->assertSame('PA', self::col($l, 197, 198));
        $this->assertSame('4125551212', self::col($l, 202, 211));
        $this->assertSame(str_pad('Retailer', 20), self::col($l, 212, 231));
        $this->assertSame('1234567', self::col($l, 232, 238));
        $this->assertSame('N', self::col($l, 239, 239));
        $this->assertSame(str_pad('12345678', 24), self::col($l, 259, 282));
        $this->assertSame('Y', self::col($l, 498, 498));
        $this->assertSame(str_pad('7', 10), self::col($l, 499, 508));
        $this->assertSame(str_repeat(' ', 43), self::col($l, 509, 551));
    }

    public function test_pur_basic_with_dollars(): void
    {
        $l = MulticatBuilder::purRecord(['type' => 'PUR', 'customer_number' => '9999', 'shipping_number' => '123', 'sku' => 'A1', 'invoice' => 'SO-1'],
            [['001', '10', 'N'], ['002', '300.00', 'NB']]);
        $this->assertSame(130, strlen($l));
        $this->assertSame('9999    ', self::col($l, 4, 11));
        $this->assertSame('123     ', self::col($l, 12, 19));
        $this->assertSame('000000000000A1', self::col($l, 28, 41));
        $this->assertSame(str_pad('SO-1', 30), self::col($l, 45, 74));
        $this->assertSame('        ', self::col($l, 75, 82));
        $this->assertSame('001', self::col($l, 103, 105));
        $this->assertSame('00000000010', self::col($l, 106, 116));
        $this->assertSame('002', self::col($l, 117, 119));
        $this->assertSame('     300.00', self::col($l, 120, 130)); // RJ/BF
    }

    /** Altria STARS p.30 sample: PUR 00000345 00009823 00009823 789010000000 20201021 qty 99, saleable -2, unsaleable -5 (no dollars: returns from col 117). */
    public function test_pur_documented_sample_altria_returns(): void
    {
        $l = MulticatBuilder::purRecord(['type' => 'PUR', 'customer_number' => '00000345', 'shipping_number' => '00009823', 'shipping_ext' => '00009823',
            'sku' => '789010000000', 'transaction_date' => '20201021'], [['001', '99', 'N'], ['004', '-2', 'N'], ['005', '-5', 'N']]);
        $this->assertSame(144, strlen($l));
        $this->assertSame('PUR', self::col($l, 1, 3));
        $this->assertSame('00000345', self::col($l, 4, 11));
        $this->assertSame('00009823', self::col($l, 12, 19));
        $this->assertSame('00009823', self::col($l, 20, 27));
        $this->assertSame('00789010000000', self::col($l, 28, 41));
        $this->assertSame('20201021', self::col($l, 75, 82));
        $this->assertSame('00000000099', self::col($l, 106, 116));
        $this->assertSame('004', self::col($l, 117, 119));
        $this->assertSame('-0000000002', self::col($l, 120, 130));
        $this->assertSame('005', self::col($l, 131, 133));
        $this->assertSame('-0000000005', self::col($l, 134, 144));
    }

    public function test_pur_altria_with_dollars_uses_documented_columns(): void
    {
        $l = MulticatBuilder::purRecord(['type' => 'PUR', 'customer_number' => '1', 'shipping_number' => '1', 'sku' => '1'],
            [['001', '-2', 'N'], ['002', '-60', 'NB'], ['004', '0', 'N'], ['005', '-2', 'N']]);
        $this->assertSame(158, strlen($l));
        $this->assertSame('004', self::col($l, 131, 133)); // Altria: Measure Code 3 at 131-133
        $this->assertSame('005', self::col($l, 145, 147)); // Measure Code 4 at 145-147
        $this->assertSame('-0000000002', self::col($l, 148, 158));
    }

    public function test_tot_columns(): void
    {
        $l = Writer::record(Layout::TOT, ['type' => 'TOT', 'distributor_id' => '12345678', 'week_ending' => '20261017', 'bid_count' => '3',
            'sid_count' => '2', 'pur_count' => '5', 'm1_code' => '001', 'm1_value' => '21.5', 'm3_code' => '003', 'm3_value' => '120'], Layout::TOT_LENGTH);
        $this->assertSame(140, strlen($l));
        $this->assertSame('12345678', self::col($l, 4, 11));
        $this->assertSame('20261017', self::col($l, 12, 19));
        $this->assertSame('000000003', self::col($l, 20, 28));
        $this->assertSame('000000002', self::col($l, 29, 37));
        $this->assertSame('000000005', self::col($l, 38, 46));
        $this->assertSame(str_repeat(' ', 40), self::col($l, 47, 86));
        $this->assertSame('001', self::col($l, 87, 89));
        $this->assertSame('0000000000021.5', self::col($l, 90, 104));
        $this->assertSame(str_repeat(' ', 18), self::col($l, 105, 122)); // dollars not reported
        $this->assertSame('003', self::col($l, 123, 125));
        $this->assertSame('000000000000120', self::col($l, 126, 140));
    }

    /** MSA requirements pp.14-15: 1.5 cartons, 0.2 of a 10-can sleeve, returns -4.0 / -.5, sign first. */
    public function test_floating_decimal_numbers(): void
    {
        $this->assertSame('000000001.5', Writer::field('1.5', 11, 'N'));
        $this->assertSame('000000000.2', Writer::field('0.2000', 11, 'N'));
        $this->assertSame('-0000000004', Writer::field('-4.0', 11, 'N'));
        $this->assertSame('-00000000.5', Writer::field('-.5', 11, 'N'));
        $this->assertSame('00000000000', Writer::field('-0', 11, 'N'));
        $this->assertSame('0.3333', Writer::number('0.33333333'));
    }

    public function test_overlong_codes_are_rejected_and_text_is_truncated(): void
    {
        $this->assertSame(str_repeat('A', 32), Writer::field(str_repeat('A', 40), 32, 'LB'));
        $this->expectException(\LengthException::class);
        Writer::field('123456789012345', 14, 'RZ');
    }

    public function test_file_name_and_week_range(): void
    {
        $this->assertSame('20261018.TOB', MulticatBuilder::fileName('2026-10-18'));
        [$a, $b] = MulticatBuilder::weekRange('2026-10-17');
        $this->assertSame('2026-10-11 00:00:00', $a->toDateTimeString());
        $this->assertSame('2026-10-17 23:59:59', $b->toDateTimeString());
    }
}
