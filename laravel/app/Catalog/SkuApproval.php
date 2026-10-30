<?php

namespace App\Catalog;

use App\Audit\Audit;
use App\Enums\TaxClass;
use App\Exceptions\WorkflowException;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Services\Money;
use App\Warehouse\ProductLookup;

/**
 * #45: new SKUs typed on the Quick Tools page are "pending" until an owner/admin approves them (and sets the tax class,
 * which the quick-tools page never sets). Pending SKUs cannot be sold; rejected ones are archived. Every step is audited.
 */
class SkuApproval
{
    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    public static function canDecide(?User $u): bool
    {
        return (bool) $u?->isAdmin();
    }

    /** The product that already uses this UPC (as its UPC or an extra barcode), if any. */
    public static function duplicateUpc(?string $upc, ?int $exceptId = null): ?Product
    {
        $upc = CatalogService::cleanUpc($upc);
        if ($upc === null) {
            return null;
        }
        $variants = ProductLookup::variants($upc);
        $p = Product::whereIn('upc', $variants)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->first();
        if ($p) {
            return $p;
        }
        $bc = ProductBarcode::with('product')->whereIn('code', $variants)->when($exceptId, fn ($q) => $q->where('product_id', '!=', $exceptId))->first();

        return $bc?->product;
    }

    public function markPending(Product $p, User $requestedBy): Product
    {
        $p->forceFill(['approval_status' => self::PENDING, 'requested_by' => $requestedBy->id, 'approved_by' => null, 'approved_at' => null])->save();
        Audit::durable('sku_requested', $p, "Quick tools: {$requestedBy->name} created {$p->sku} (waiting for approval)", ['requested_by' => $requestedBy->id]);

        return $p;
    }

    /** @param  array{tax_class: string, price?: mixed, note?: ?string}  $data */
    public function approve(Product $p, array $data, User $by): Product
    {
        $this->assertCanDecide($p, $by);
        $class = TaxClass::tryFrom((string) ($data['tax_class'] ?? ''));
        if (! $class) {
            throw new WorkflowException('Pick a tax class before approving.');
        }
        $fill = ['approval_status' => null, 'approved_by' => $by->id, 'approved_at' => now(), 'tax_class' => $class->value, 'approval_note' => $data['note'] ?? null];
        if (isset($data['price']) && is_numeric($data['price'])) {
            $fill['price'] = Money::s($data['price']);
        }
        if (in_array($class->value, Product::PACT_DEFAULT_CLASSES, true)) {
            $fill['pact_covered'] = true;
        }
        $p->forceFill($fill)->save();
        Audit::durable('sku_approved', $p, "{$by->name} approved {$p->sku} as ".$class->label(), ['tax_class' => $class->value, 'price' => $p->price]);

        return $p->fresh();
    }

    public function reject(Product $p, ?string $note, User $by): Product
    {
        $this->assertCanDecide($p, $by);
        $p->forceFill(['approval_status' => self::REJECTED, 'approved_by' => $by->id, 'approved_at' => now(), 'approval_note' => $note])->save();
        $p->archive($by);
        Audit::durable('sku_rejected', $p, "{$by->name} rejected {$p->sku}".($note ? ": {$note}" : ''), ['note' => $note]);

        return $p->fresh();
    }

    private function assertCanDecide(Product $p, User $by): void
    {
        if (! self::canDecide($by)) {
            throw new WorkflowException('Only an owner or admin can approve or reject new SKUs.');
        }
        if ($p->approval_status !== self::PENDING) {
            throw new WorkflowException("{$p->sku} is not waiting for approval.");
        }
    }
}
