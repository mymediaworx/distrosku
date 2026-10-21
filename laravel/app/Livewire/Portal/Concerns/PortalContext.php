<?php

namespace App\Livewire\Portal\Concerns;

use App\Models\CustomerContact;
use App\Models\ShipTo;
use App\Portal\PortalService;
use Illuminate\Support\Facades\Auth;

/** Signed-in contact, chosen ship-to and session cart shared by portal pages. */
trait PortalContext
{
    public ?int $shipToId = null;

    /** 0.19.6: counter pickup unless the customer chooses Delivery. */
    public string $fulfillment = 'pickup';

    /** @var array<int|string, string> product_id => qty */
    public array $cart = [];

    public function bootPortalContext(): void
    {
        abort_unless($this->contact()?->canUsePortal(), 403);
    }

    public function mountPortalContext(): void
    {
        $this->shipToId = session('portal.ship_to');
        $this->shipToId = $this->shipTo()?->id;
        $this->fulfillment = session('portal.fulfillment') === 'delivery' ? 'delivery' : 'pickup';
        $this->cart = session($this->cartKey(), []);
    }

    protected function contact(): ?CustomerContact
    {
        return Auth::guard('portal')->user();
    }

    protected function shipTo(): ?ShipTo
    {
        return app(PortalService::class)->shipToFor($this->contact(), $this->shipToId);
    }

    protected function isDelivery(): bool
    {
        return $this->fulfillment === 'delivery';
    }

    /** Where compliance is checked: the ship-to for delivery, else the pickup counter (main warehouse). Null = delivery with no ship-to. */
    protected function location(): ?ShipTo
    {
        return $this->isDelivery() ? $this->shipTo()
            : \App\Models\SalesOrder::pickupLocation(\App\Models\Warehouse::where('code', 'MAIN')->value('id'), $this->contact()?->customer_id);
    }

    public function updatedFulfillment(): void
    {
        $this->fulfillment = $this->fulfillment === 'delivery' ? 'delivery' : 'pickup';
        session(['portal.fulfillment' => $this->fulfillment]);
    }

    protected function cartKey(): string
    {
        return 'portal.cart.'.Auth::guard('portal')->id();
    }

    protected function saveCart(): void
    {
        $this->cart = array_filter(array_map(fn ($q) => (string) max(0, (float) $q), $this->cart), fn ($q) => (float) $q > 0);
        session([$this->cartKey() => $this->cart]);
    }

    public function updatedShipToId(): void
    {
        $this->shipToId = $this->shipTo()?->id;
        session(['portal.ship_to' => $this->shipToId]);
    }
}
