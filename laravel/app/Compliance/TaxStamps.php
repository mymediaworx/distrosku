<?php

namespace App\Compliance;

use App\Audit\Audit;
use App\Exceptions\WorkflowException;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TaxStampAffixing;
use App\Models\TaxStampPurchase;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * #40: cigarette tax stamping for stamping agents. OFF by default (Vega buys stamped cigarettes); a per-install setting.
 * - Buy stamps (state, pack size 20/25, count, cost).
 * - Affix: N packs of an unstamped SKU become N packs of the stamped SKU (stock moves out/in), using N stamps.
 *   Refused when not enough stamps are on hand for that state + pack size.
 * - Reconciliation: stamps bought - stamps affixed = on hand, per state and pack size, for any period.
 * - Stamped stock (a SKU with a stamp state) can't go to a customer in another state.
 */
class TaxStamps
{
    public static function enabled(): bool
    {
        return (string) Setting::get('stamping_enabled', '0') === '1';
    }

    public static function onHand(string $state, int $packSize, ?string $asOf = null): int
    {
        $asOf ??= today()->toDateString();
        $bought = (int) TaxStampPurchase::where('state', $state)->where('pack_size', $packSize)->whereDate('purchased_on', '<=', $asOf)->sum('stamps');
        $used = (int) TaxStampAffixing::where('state', $state)->where('pack_size', $packSize)->whereDate('affixed_on', '<=', $asOf)->sum('packs');

        return $bought - $used;
    }

    public function buy(array $d, User $by): TaxStampPurchase
    {
        $this->assertOn();
        $stamps = (int) ($d['stamps'] ?? 0);
        if ($stamps <= 0 || ! in_array((int) ($d['pack_size'] ?? 20), [20, 25], true) || ! preg_match('/^[A-Z]{2}$/', strtoupper((string) ($d['state'] ?? '')))) {
            throw new WorkflowException('Enter the state, pack size (20 or 25) and number of stamps.');
        }
        $unit = Money::s($d['unit_cost'] ?? 0);
        $p = TaxStampPurchase::create(['state' => strtoupper($d['state']), 'pack_size' => (int) ($d['pack_size'] ?? 20), 'stamps' => $stamps, 'unit_cost' => $unit,
            'total' => Money::mul($unit, $stamps, 2), 'purchased_on' => Carbon::parse($d['purchased_on'] ?? today())->toDateString(),
            'reference' => $d['reference'] ?? null, 'user_id' => $by->id]);
        Audit::action('tax_stamps_bought', $p, "{$stamps} {$p->state} stamps ({$p->pack_size}s) bought", ['stamps' => $stamps, 'total' => $p->total]);

        return $p;
    }

    public function affix(array $d, User $by): TaxStampAffixing
    {
        $this->assertOn();
        $from = Product::findOrFail($d['from_product_id']);
        $to = Product::findOrFail($d['to_product_id']);
        $wh = Warehouse::findOrFail($d['warehouse_id']);
        $packs = (int) ($d['packs'] ?? 0);
        $state = strtoupper((string) $to->stamp_state);
        $size = (int) ($d['pack_size'] ?? 20);
        if ($packs <= 0) {
            throw new WorkflowException('Enter how many packs were stamped.');
        }
        if ($state === '' || $from->stamp_state) {
            throw new WorkflowException('Pick an unstamped SKU (no stamp state) and the stamped SKU it becomes (with a stamp state).');
        }
        if ($from->id === $to->id) {
            throw new WorkflowException('The unstamped and stamped SKUs must be different.');
        }

        return DB::transaction(function () use ($from, $to, $wh, $packs, $state, $size, $d, $by) {
            $have = self::onHand($state, $size);
            if ($have < $packs) {
                throw new WorkflowException("Only {$have} {$state} stamps ({$size}s) on hand; {$packs} needed. Record the stamp purchase first.");
            }
            $a = TaxStampAffixing::create(['state' => $state, 'pack_size' => $size, 'packs' => $packs, 'from_product_id' => $from->id, 'to_product_id' => $to->id,
                'warehouse_id' => $wh->id, 'affixed_on' => Carbon::parse($d['affixed_on'] ?? today())->toDateString(), 'notes' => $d['notes'] ?? null, 'user_id' => $by->id]);
            $inv = app(InventoryService::class);
            $inv->move($from, $wh, -$packs, 'stamping', $a, null, "Stamped {$packs} as {$to->sku} ({$state})");
            $inv->move($to, $wh, $packs, 'stamping', $a, $from->cost, "Stamped from {$from->sku} ({$state})");
            Audit::action('tax_stamps_affixed', $a, "{$packs} packs {$from->sku} stamped {$state} as {$to->sku}", ['packs' => $packs, 'state' => $state]);

            return $a;
        });
    }

    /** @return list<array{state: string, pack_size: int, opening: int, bought: int, affixed: int, closing: int}> */
    public static function reconciliation(string $from, string $to): array
    {
        $keys = TaxStampPurchase::select('state', 'pack_size')->distinct()->get()->concat(TaxStampAffixing::select('state', 'pack_size')->distinct()->get())
            ->map(fn ($r) => $r->state.'|'.$r->pack_size)->unique()->sort()->values();
        $dayBefore = Carbon::parse($from)->subDay()->toDateString();

        return $keys->map(function ($k) use ($from, $to, $dayBefore) {
            [$state, $size] = explode('|', $k);
            $bought = (int) TaxStampPurchase::where('state', $state)->where('pack_size', $size)->whereDate('purchased_on', '>=', $from)->whereDate('purchased_on', '<=', $to)->sum('stamps');
            $affixed = (int) TaxStampAffixing::where('state', $state)->where('pack_size', $size)->whereDate('affixed_on', '>=', $from)->whereDate('affixed_on', '<=', $to)->sum('packs');
            $opening = self::onHand($state, (int) $size, $dayBefore);

            return ['state' => $state, 'pack_size' => (int) $size, 'opening' => $opening, 'bought' => $bought, 'affixed' => $affixed, 'closing' => $opening + $bought - $affixed];
        })->all();
    }

    /** Stamped stock can't go to a customer in another state (delivery: ship-to state; pickup: the company's state). */
    public static function assertDestination(?Product $p, ?string $destState, string $field = 'product_id'): void
    {
        if (! self::enabled() || ! $p || ! $p->stamp_state || ! $destState) {
            return;
        }
        if (strtoupper($destState) !== strtoupper($p->stamp_state)) {
            throw ValidationException::withMessages([$field => "{$p->sku} carries a {$p->stamp_state} tax stamp and can't be sold into ".strtoupper($destState).'.']);
        }
    }

    private function assertOn(): void
    {
        if (! self::enabled()) {
            throw new WorkflowException('Cigarette stamping is off (Company Settings > Compliance).');
        }
    }
}
