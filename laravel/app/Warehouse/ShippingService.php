<?php

namespace App\Warehouse;

use App\Compliance\PactCategory;
use App\Exceptions\WorkflowException;
use App\Models\Carrier;
use App\Models\Package;

/** Manual carrier + tracking on cartons (no live carrier API). */
class ShippingService
{
    public function assign(Package $pkg, ?int $carrierId, ?string $tracking, ?string $service = null): Package
    {
        $carrier = $carrierId ? Carrier::where('active', true)->find($carrierId) : null;
        if ($carrierId && ! $carrier) {
            throw new WorkflowException('Unknown or inactive carrier.');
        }
        $tracking = trim((string) $tracking);
        if ($tracking !== '' && ! preg_match('/^[A-Za-z0-9 -]{4,60}$/', $tracking)) {
            throw new WorkflowException('Tracking number may only contain letters, digits, spaces and dashes.');
        }
        if ($carrier?->is_postal) {
            $pkg->loadMissing('lines.pickLine.product');
            $bad = $pkg->lines->first(fn ($l) => in_array(PactCategory::of($l->pickLine->product), ['cigarette', 'ryo', 'smokeless', 'ends'], true));
            if ($bad) {
                throw new WorkflowException("{$bad->pickLine->product->sku} cannot go by {$carrier->name}: cigarettes, smokeless tobacco and ENDS are nonmailable (18 U.S.C. 1716E).");
            }
        }
        $pkg->update(['carrier_id' => $carrier?->id, 'tracking_number' => $tracking ?: null, 'service' => $service ? mb_substr(trim($service), 0, 40) : null,
            'shipped_at' => $carrier && $tracking !== '' ? ($pkg->shipped_at ?? now()) : $pkg->shipped_at]);

        return $pkg->fresh('carrier');
    }
}
