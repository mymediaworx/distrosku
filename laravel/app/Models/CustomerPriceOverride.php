<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 0.19: special price for one customer (or all customers) on one product.
 * duration: one_time (used by the first confirmed order line, then marked used), range (starts_on..ends_on), ongoing.
 */
class CustomerPriceOverride extends Model
{
    protected $guarded = ['id'];

    public const DURATIONS = ['one_time' => 'One time', 'range' => 'Date range', 'ongoing' => 'Ongoing'];

    protected static function booted(): void
    {
        // Server-side: only owner/admin create or change special prices (CLI / seeding / the confirm-time "used" mark are allowed).
        static::saving(function (self $o) {
            $u = auth()->user();
            $onlyUse = $o->exists && ! array_diff(array_keys($o->getDirty()), ['used_at', 'used_sales_order_id', 'updated_at']);
            if ($u && ! $onlyUse && ! $u->canModule('customer_prices', 'manage')) {
                \App\Audit\Audit::durable('price_override_blocked', $o->exists ? $o : null, "Blocked: {$u->name} tried to change a customer special price");
                throw new \App\Exceptions\WorkflowException('Only an owner or admin can create or change customer special prices.');
            }
        });
        static::deleting(function (self $o) {
            $u = auth()->user();
            if ($u && ! $u->canModule('customer_prices', 'manage')) {
                throw new \App\Exceptions\WorkflowException('Only an owner or admin can delete customer special prices.');
            }
        });
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:4', 'starts_on' => 'date', 'ends_on' => 'date', 'active' => 'boolean', 'used_at' => 'datetime'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /** Overrides that can price $product for $customer on $date, best first (customer-specific, then all-customers; newest). */
    public static function candidates(Product $product, ?Customer $customer, string $date)
    {
        return static::query()->where('product_id', $product->id)->where('active', true)->whereNull('used_at')
            ->where(fn ($q) => $q->whereNull('customer_id')->when($customer, fn ($w) => $w->orWhere('customer_id', $customer->id)))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $date))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))
            ->get()->sortByDesc(fn (self $o) => sprintf('%d|%010d', $o->customer_id ? 1 : 0, $o->id))->values();
    }

    public function scopeLabel(): string
    {
        return $this->customer_id ? 'override' : 'override_all';
    }

    public function status(): string
    {
        if ($this->used_at) {
            return 'Used';
        }
        if (! $this->active) {
            return 'Inactive';
        }
        $today = Carbon::today();
        if ($this->ends_on && $this->ends_on->lt($today)) {
            return 'Expired';
        }

        return $this->starts_on && $this->starts_on->gt($today) ? 'Scheduled' : 'Active';
    }
}
