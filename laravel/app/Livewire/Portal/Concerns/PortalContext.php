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
