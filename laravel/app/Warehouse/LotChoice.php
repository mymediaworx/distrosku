<?php

namespace App\Warehouse;

use App\Models\Product;

/**
 * #36 follow-up: the lot picker on POS and van sales. Shown only when a lot-tracked item has 2+ sellable lots in
 * the selling warehouse (one lot is assigned by the server automatically). Defaults to the first-expiring lot (FEFO);
 * the server re-checks the chosen lot at checkout (LotService::assertSellable).
 */
class LotChoice
{
    /** @return array{lots: array<int, string>, lot_id: ?int} */
    public static function forLine(Product $p, ?int $warehouseId): array
    {
        if (! $p->track_lots || ! $warehouseId) {
            return ['lots' => [], 'lot_id' => null];
        }
        $on = app(LotService::class)->lotsOnHand($p, $warehouseId);
        if (count($on) < 2) {
            return ['lots' => [], 'lot_id' => null];
        }
        $lots = [];
        foreach ($on as $l) {
            $lots[$l['lot_id']] = $l['lot_number'].($l['expires_on'] ? ' · exp '.$l['expires_on'] : '').' · '.rtrim(rtrim($l['qty'], '0'), '.').' on hand'.($l['coa'] ? '' : ' · no COA');
        }

        return ['lots' => $lots, 'lot_id' => (int) array_key_first($lots)];
    }

    /** The cart line's lot, only if it is one of the lots offered for that line. */
    public static function picked(array $line): ?int
    {
        $id = isset($line['lot_id']) && $line['lot_id'] !== '' ? (int) $line['lot_id'] : null;

        return $id && isset($line['lots'][$id]) ? $id : null;
    }
}
