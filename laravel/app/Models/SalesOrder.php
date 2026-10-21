<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use App\Models\Concerns\HasActivity;
use App\Services\Money;
use App\Services\Sequence;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    use HasActivity;
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['amount_paid' => '0', 'amount_credited' => '0', 'subtotal' => '0', 'total' => '0', 'status' => 'draft'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (blank($m->number)) {
                $m->number = Sequence::next('SO', static::class);
            }
            // 0.19.6: every sale is counter pickup unless the user explicitly chose delivery (the ship-to no longer decides).
            if (blank($m->fulfillment)) {
                $m->fulfillment = self::PICKUP;
            }
            if (blank($m->sales_rep_id) && $m->customer_id) {
                $m->sales_rep_id = Customer::whereKey($m->customer_id)->value('sales_rep_id');
            }
        });
        static::saving(function (self $m) {
            if (blank($m->fulfillment)) {
                $m->fulfillment = self::PICKUP;
            }
            if ($m->needsShipTo() && blank($m->ship_to_id)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['ship_to_id' => 'Choose a ship-to for a delivery (or switch the sale to Counter pickup).']);
            }
        });
    }

    /** 0.19.6: stored values. 'pickup' = counter pickup (the default). */
    public const PICKUP = 'pickup';

    public const DELIVERY = 'delivery';

    public const SHIP = 'ship';

    public const FULFILLMENT = [self::PICKUP => 'Counter pickup', self::DELIVERY => 'Delivery', self::SHIP => 'Ship (carrier)'];

    public function isPickup(): bool
    {
        return ($this->fulfillment ?: self::PICKUP) === self::PICKUP;
    }

    public function isDelivery(): bool
    {
        return $this->fulfillment === self::DELIVERY;
    }

    /** Delivery and carrier shipments need an address; counter pickup never does. */
    public function needsShipTo(): bool
    {
        return in_array($this->fulfillment, [self::DELIVERY, self::SHIP], true);
    }

    public function fulfillmentLabel(): string
    {
        return self::FULFILLMENT[$this->fulfillment ?: self::PICKUP] ?? 'Counter pickup';
    }

    /**
     * Address used for compliance checks and state reports: the ship-to when the sale has one, else (counter pickup)
     * see pickupLocation(). Never saved on the order.
     */
    public function location(): ShipTo
    {
        return $this->shipTo ?? self::pickupLocation($this->warehouse_id, $this->customer_id);
    }

    /**
     * 0.19.6: compliance location for a counter-pickup sale with no ship-to: the customer's default ship-to when they have
     * one (so licence / state rules are the same as before 0.19.6), else an unsaved ship-to built from the selling warehouse.
     */
    public static function pickupLocation(?int $warehouseId, ?int $customerId = null): ShipTo
    {
        if ($customerId && ($st = Customer::find($customerId)?->defaultShipTo())) {
            return $st;
        }
        $w = $warehouseId ? Warehouse::find($warehouseId) : null;
        $w ??= Warehouse::where('code', 'MAIN')->first() ?? Warehouse::first();

        return new ShipTo(['customer_id' => $customerId, 'name' => 'Counter pickup', 'address' => $w?->address, 'city' => $w?->city,
            'state' => strtoupper((string) ($w?->state ?: config('erp.home_state', 'KY'))), 'fulfillment' => self::PICKUP]);
    }

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'order_date' => 'date',
            'ship_date' => 'date',
            'elig_override_at' => 'datetime',
            'elig_warnings' => 'array',
            'compliance_reasons' => 'array',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'invoiced_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'total' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'amount_credited' => 'decimal:4',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesRep()
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    public function shipTo()
    {
        return $this->belongsTo(ShipTo::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function creditMemos()
    {
        return $this->hasMany(CreditMemo::class);
    }

    /** Open AR on this order: total - credited - paid. */
    public function balance(): string
    {
        return Money::s(Money::of($this->total)->minus(Money::of($this->amount_credited))->minus(Money::of($this->amount_paid)));
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function signatures()
    {
        return $this->hasMany(DeliverySignature::class);
    }

    public function deliveryStops()
    {
        return $this->hasMany(DeliveryStop::class);
    }
}
