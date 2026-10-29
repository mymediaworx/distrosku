<?php

namespace App\Models;

use App\Enums\TaxClass;
use App\Models\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    use HasActivity;
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['active' => true, 'cost' => 0, 'track_lots' => false, 'base_unit_name' => 'Each'];

    protected function casts(): array
    {
        return [
            'tax_class' => TaxClass::class,
            'pact_covered' => 'boolean',
            'msa_promo' => 'boolean',
            'msa_exclude' => 'boolean',
            'msa_units_per_sale' => 'decimal:6',
            'active' => 'boolean',
            'track_lots' => 'boolean',
            'cost' => 'decimal:4',
            'price' => 'decimal:4',
            'reorder_point' => 'decimal:4',
            'thc_mg' => 'decimal:4',
            'thc_total_mg' => 'decimal:4',
            'thc_serving_mg' => 'decimal:4',
            'synthetic_cannabinoid' => 'boolean',
            'flavored' => 'boolean',
            'net_weight_oz' => 'decimal:4',
            'premium_cigar' => 'boolean',
            'ky_tax_paid_by_supplier' => 'boolean',
            'archived_at' => 'datetime',
            'hemp_cannabinoid' => 'boolean',
            'taxes' => 'array',
        ];
    }

    public const SUBTYPES = ['cbd' => 'CBD', 'hemp' => 'Hemp (THC)', 'kratom' => 'Kratom', 'vape' => 'Vape'];

    /** 0.18.1: physical form (KY CHFS registry "Type of product" values + flower / tincture). */
    public const FORMS = ['vape' => 'Vape', 'gummy' => 'Gummy', 'beverage' => 'Beverage', 'other_edible' => 'Other edible', 'topical' => 'Topical',
        'tincture' => 'Tincture', 'flower' => 'Flower / pre-roll', 'other' => 'Other'];

    /** 0.18.1: a vape that is (or may be) a vapor product. */
    public function isVapeForm(): bool
    {
        return $this->product_form === 'vape' || $this->product_subtype === 'vape' || $this->tax_class?->value === 'vapor';
    }

    /** 0.18.1: contains a hemp-derived cannabinoid (CBD counts, zero-THC CBD included). */
    public function hasCannabinoid(): bool
    {
        return in_array($this->product_subtype, ['hemp', 'cbd'], true) || (bool) $this->hemp_cannabinoid || filled($this->hemp_type)
            || (bool) $this->synthetic_cannabinoid || (float) $this->thc_total_mg > 0 || (float) $this->thc_serving_mg > 0 || (float) $this->thc_mg > 0;
    }

    /** 0.18.1 "THC vape" (rule selector thc_vape): vape form AND a hemp/CBD/THC cannabinoid. Nicotine-only vapes are not THC vapes. */
    public function isThcVape(): bool
    {
        return $this->isVapeForm() && $this->hasCannabinoid();
    }

    public const FDA_STATUSES = ['authorized' => 'Authorized (marketing order)', 'pending_pmta' => 'Pending PMTA', 'denied' => 'Denied (MDO)', 'none' => 'None / not submitted'];

    public function coas()
    {
        return $this->hasMany(ProductCoa::class)->orderByDesc('tested_on');
    }

    public function vapeListings()
    {
        return $this->hasMany(VapeDirectoryListing::class);
    }

    public function scopeNotArchived($q)
    {
        return $q->whereNull($q->getModel()->qualifyColumn('archived_at'));
    }

    /** 0.21: regulated = tobacco, vapor, hemp/CBD, kratom (every tax class except General and Beverage). Walk-in/cash customers cannot buy these. */
    public function isRegulated(): bool
    {
        return (bool) $this->tax_class?->isRegulated();
    }

    public function scopeRegulated($q)
    {
        return $q->whereIn('tax_class', \App\Enums\TaxClass::regulatedValues());
    }

    public function scopeNotRegulated($q)
    {
        return $q->where(fn ($w) => $w->whereNull('tax_class')->orWhereNotIn('tax_class', \App\Enums\TaxClass::regulatedValues()));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archivedBy()
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function archive(?User $by = null): void
    {
        $this->forceFill(['archived_at' => now(), 'archived_by' => $by?->id])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null, 'archived_by' => null])->save();
    }

    /** Sales, purchasing, returns or inventory movement history: such products can only be archived, not deleted. */
    public function hasHistory(): bool
    {
        return SalesOrderLine::where('product_id', $this->id)->exists()
            || PurchaseOrderLine::where('product_id', $this->id)->exists()
            || StockMovement::where('product_id', $this->id)->exists()
            || CreditMemoLine::where('product_id', $this->id)->exists()
            || Inventory::where('product_id', $this->id)->where(fn ($q) => $q->where('qty', '!=', 0)->orWhere('reserved', '!=', 0))->exists();
    }

    /** Throws a validation error when an archived product is put on a new order line. */
    public static function assertOrderable($productId, string $field = 'product_id'): void
    {
        $p = $productId ? static::find($productId) : null;
        if ($p && $p->isArchived()) {
            throw ValidationException::withMessages([$field => "Product {$p->sku} is archived and can't be added to new orders."]);
        }
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    public function buydowns()
    {
        return $this->hasMany(Buydown::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function sharedProduct()
    {
        return $this->belongsTo(SharedProduct::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Barcode value = SKU code. */
    public function barcodeValue(): string
    {
        return (string) $this->sku;
    }

    public function units()
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function barcodes()
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function lots()
    {
        return $this->hasMany(Lot::class);
    }

    public function preferredVendor()
    {
        return $this->belongsTo(Vendor::class, 'preferred_vendor_id');
    }

    public function vendorItems()
    {
        return $this->hasMany(VendorItem::class);
    }

    public const PACT_DEFAULT_CLASSES = ['cigarette_stamped', 'ryo', 'snuff_chew', 'vapor'];

    /** PACT covered defaults on for cigarette / RYO / smokeless / vapor unless set explicitly. */
    protected static function booted(): void
    {
        // 0.20.15 (#36): new products in a lot-tracked department / tax class inherit tracking (can be switched off per product after).
        static::creating(function (Product $p) {
            if (! $p->track_lots && \App\Warehouse\LotTracking::appliesTo($p)) {
                $p->track_lots = true;
            }
        });
        static::saving(function (Product $p) {
            $cls = $p->tax_class instanceof \BackedEnum ? $p->tax_class->value : $p->tax_class;
            $explicit = $p->exists ? $p->isDirty('pact_covered') : array_key_exists('pact_covered', $p->getAttributes());
            if (in_array($cls, self::PACT_DEFAULT_CLASSES, true) && ! $explicit && ($p->isDirty('tax_class') || ! $p->exists)) {
                $p->pact_covered = true;
            }
        });
    }
}
