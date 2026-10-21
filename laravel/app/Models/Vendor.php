<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use HasActivity;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_manufacturer' => 'boolean',
            'ky_tobacco_licensed' => 'boolean',
        ];
    }

    public function vendorItems()
    {
        return $this->hasMany(VendorItem::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
