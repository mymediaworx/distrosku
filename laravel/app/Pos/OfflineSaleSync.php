<?php

namespace App\Pos;

use App\Audit\Audit;
use App\Exceptions\ComplianceException;
use App\Models\Customer;
use App\Models\OfflineSale;
use App\Models\Product;
use App\Models\User;
use App\Pricing\PricingEngine;
use App\Services\Money;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Issue #26: sales recorded on a device without a connection arrive here when it reconnects.
 * Each one runs through the same PosCheckout as a live sale. The device's UUID makes a resend harmless.
 * A sale the server can't complete (compliance, credit limit, stock, card tender, stale prices...) is kept
 * as "needs review" with the reason, never dropped: the goods and money already changed hands.
 */
final class OfflineSaleSync
{
    /** Michael (Oct 23): offline selling stops when the device's saved catalog/prices are older than this. */
    public const MAX_CATALOG_AGE_HOURS = 48;

    /** Michael (Oct 23): cash, check and charge to account work offline; card can't be authorized offline. */
    public const OFFLINE_TENDERS = ['cash', 'check', 'account'];

    public function __construct(private PosCheckout $checkout, private PricingEngine $pricing) {}

    /** Store (once) and process one sale from a device. */
    public function ingest(array $sale, User $syncedBy, ?string $deviceId = null, string $channel = 'pos'): OfflineSale
    {
        $uuid = (string) ($sale['uuid'] ?? '');
        if (! Str::isUuid($uuid)) {
            throw ValidationException::withMessages(['uuid' => 'Each offline sale needs a UUID from the device.']);
        }
        if ($existing = OfflineSale::where('uuid', $uuid)->first()) {
            return $existing; // already received: a resend changes nothing
        }

        try {
            $row = OfflineSale::create([
                'uuid' => $uuid, 'device_id' => $deviceId ? mb_substr($deviceId, 0, 64) : null, 'channel' => $channel,
                'user_id' => $this->cashier($sale['user_id'] ?? null)?->id, 'synced_by' => $syncedBy->id,
                'warehouse_id' => is_numeric($sale['warehouse_id'] ?? null) ? (int) $sale['warehouse_id'] : null,
                'customer_id' => is_numeric($sale['customer_id'] ?? null) && Customer::whereKey($sale['customer_id'])->exists() ? (int) $sale['customer_id'] : null,
                'sold_at' => $this->time($sale['sold_at'] ?? null), 'tender' => is_string($sale['tender'] ?? null) ? mb_substr($sale['tender'], 0, 20) : null,
                'total' => is_numeric($sale['total'] ?? null) ? Money::s($sale['total']) : '0', 'payload' => $sale,
                'status' => OfflineSale::PENDING, 'synced_at' => now(),
            ]);
        } catch (QueryException $e) {
            // two syncs of the same sale at once: the other one won the unique UUID
            return OfflineSale::where('uuid', $uuid)->firstOrFail();
        }

        return $this->process($row);
    }

    /** Try (or retry) completing a stored offline sale. $creditOverrideBy + reason: owner/admin resolving a credit-limit block. */
    public function process(OfflineSale $row, ?User $creditOverrideBy = null, ?string $creditReason = null): OfflineSale
    {
        if (in_array($row->status, [OfflineSale::COMPLETED, OfflineSale::RESOLVED], true)) {
            return $row;
        }
        $row->increment('attempts');
        $sale = $row->payload;

        $problems = $this->precheck($sale);
        if ($problems) {
            return $this->review($row, $problems);
        }

        try {
            $so = $this->checkout->complete([
                'customer_id' => (int) $sale['customer_id'], 'warehouse_id' => (int) $sale['warehouse_id'],
                'fulfillment' => $sale['fulfillment'] ?? 'pickup', 'ship_to_id' => $sale['ship_to_id'] ?? null,
                'notes' => trim('Offline sale '.mb_substr($row->uuid, 0, 8).'. '.($sale['notes'] ?? '')),
                'tender' => $sale['tender'], 'signature' => $sale['signature'] ?? null,
                'order_date' => ($row->sold_at ?? now())->timezone(config('app.timezone'))->toDateString(),
                'credit_override_reason' => $creditReason,
                'lines' => array_map(fn ($l) => ['product_id' => (int) $l['product_id'], 'qty' => $l['qty'], 'price' => $l['price']], $sale['lines']),
            ], $creditOverrideBy);
        } catch (ComplianceException $e) {
            return $this->review($row, ['Compliance check failed on the server: '.$this->complianceText($e)]);
        } catch (ValidationException $e) {
            return $this->review($row, array_values(array_unique($e->validator->errors()->all())));
        } catch (Throwable $e) {
            if (! $e instanceof \App\Exceptions\WorkflowException && ! $e instanceof \App\Exceptions\InventoryException) { // CreditLimitException is a WorkflowException
                report($e); // unexpected: still kept for review, never dropped
            }

            return $this->review($row, [$e->getMessage()]);
        }

        $row->update(['status' => OfflineSale::COMPLETED, 'sales_order_id' => $so->id, 'problems' => null, 'warnings' => $this->warnings($row, $sale) ?: null]);
        Audit::action('offline_sale_synced', $so, "Offline sale {$row->uuid} recorded as {$so->number}", [
            'device_id' => $row->device_id, 'sold_at' => $row->sold_at?->toIso8601String(), 'cashier_id' => $row->user_id, 'attempts' => $row->attempts,
        ]);

        return $row->fresh();
    }

    /** Owner/admin closes a sale that was handled outside the system (e.g. goods returned, entered by hand). */
    public function resolve(OfflineSale $row, User $by, string $note): OfflineSale
    {
        $row->update(['status' => OfflineSale::RESOLVED, 'resolved_by' => $by->id, 'resolved_at' => now(), 'resolution_note' => mb_substr(trim($note), 0, 2000)]);
        Audit::action('offline_sale_resolved', $row, "Offline sale {$row->uuid} marked resolved: {$note}", ['problems' => $row->problems], [], $by->id);

        return $row;
    }

    /** @return list<string> reasons the sale can't be completed as sent */
    private function precheck(array $sale): array
    {
        $v = Validator::make($sale, [
            'customer_id' => 'required|integer|exists:customers,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'tender' => 'required|string',
            'lines' => 'required|array|min:1|max:500',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.qty' => 'required|numeric|gt:0|max:1000000',
            'lines.*.price' => 'required|numeric|min:0|max:1000000',
            'fulfillment' => 'nullable|in:pickup,delivery',
            'ship_to_id' => 'nullable|integer',
            'sold_at' => 'nullable|date',
            'catalog_at' => 'nullable|date',
        ]);
        if ($v->fails()) {
            return array_values(array_unique($v->errors()->all()));
        }
        $problems = [];
        if (! in_array($sale['tender'], self::OFFLINE_TENDERS, true)) {
            $problems[] = "Payment type \"{$sale['tender']}\" can't be taken offline (cash, check or charge to account only).";
        }
        $soldAt = $this->time($sale['sold_at'] ?? null);
        $catalogAt = $this->time($sale['catalog_at'] ?? null);
        if ($soldAt && $catalogAt && $catalogAt->diffInHours($soldAt, false) > self::MAX_CATALOG_AGE_HOURS) {
            $problems[] = 'Sold with saved prices older than '.self::MAX_CATALOG_AGE_HOURS.' hours (prices from '.$catalogAt->timezone(config('app.timezone'))->format('M j g:i A').').';
        }

        return $problems;
    }

    /** @return list<string> notes on a completed sale worth a manager's glance */
    private function warnings(OfflineSale $row, array $sale): array
    {
        $out = [];
        $customer = Customer::find($sale['customer_id']);
        $date = ($row->sold_at ?? now())->timezone(config('app.timezone'))->toDateString();
        foreach ($sale['lines'] as $l) {
            $p = Product::find($l['product_id']);
            $q = $p ? $this->pricing->quote($p, $customer, $l['qty'], $date) : null;
            if ($q && ! Money::of($q->price)->isEqualTo(Money::of($l['price']))) {
                $out[] = "{$p->sku}: sold offline at \$".number_format((float) $l['price'], 2).'; current price $'.number_format((float) $q->price, 2).'.';
            }
        }
        $sum = Money::add(...array_map(fn ($l) => Money::mul($l['qty'], $l['price']), $sale['lines']));
        if (is_numeric($sale['total'] ?? null) && ! Money::of($sum)->isEqualTo(Money::of($sale['total']))) {
            $out[] = 'Device total $'.number_format((float) $sale['total'], 2).' differs from its lines ($'.number_format((float) $sum, 2).'). Lines were used.';
        }
        if (($sale['user_id'] ?? null) !== null && ! $row->user_id) {
            $out[] = 'The cashier on the device is not an active POS user; recorded under the user who synced.';
        }

        return $out;
    }

    private function review(OfflineSale $row, array $problems): OfflineSale
    {
        $row->update(['status' => OfflineSale::NEEDS_REVIEW, 'problems' => array_values($problems)]);

        return $row->fresh();
    }

    private function cashier(mixed $id): ?User
    {
        $u = is_numeric($id) ? User::find($id) : null;

        return $u && $u->active && $u->canModule('pos', 'manage') ? $u : null;
    }

    private function time(mixed $v): ?Carbon
    {
        try {
            return is_string($v) && $v !== '' ? Carbon::parse($v)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function complianceText(ComplianceException $e): string
    {
        return $e->reasons ? collect($e->reasons)->map(fn ($m, $sku) => $sku.': '.implode(' ', (array) $m))->implode(' | ') : $e->getMessage();
    }
}
