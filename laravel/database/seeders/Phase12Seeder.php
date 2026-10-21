<?php

namespace Database\Seeders;

use App\Models\Carrier;
use Illuminate\Database\Seeder;

/** Idempotent: default carriers with public tracking links (no API). Edit addresses/phones for the PACT report. */
class Phase12Seeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['OWN', 'Own delivery (company truck)', null, false],
            ['UPS', 'UPS', 'https://www.ups.com/track?tracknum={tracking}', false],
            ['FEDEX', 'FedEx', 'https://www.fedex.com/fedextrack/?trknbr={tracking}', false],
            ['DHL', 'DHL Express', 'https://www.dhl.com/us-en/home/tracking/tracking-express.html?submit=1&tracking-id={tracking}', false],
            ['USPS', 'USPS', 'https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking}', true],
        ];
        foreach ($rows as [$code, $name, $url, $postal]) {
            Carrier::firstOrCreate(['code' => $code], ['name' => $name, 'tracking_url' => $url, 'is_postal' => $postal]);
        }
    }
}
