<?php

namespace App\Livewire\Driver;

use App\Models\DeliveryManifest;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.driver')]
#[Title('Today\'s stops')]
class Today extends Component
{
    public string $date = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canModule('driver_app', 'view'), 403);
        $this->date = request('date', now()->toDateString());
    }

    public function render()
    {
        $u = auth()->user();
        $manifests = DeliveryManifest::with(['stops.salesOrder.customer', 'stops.salesOrder.shipTo'])
            ->whereDate('delivery_date', $this->date)
            ->when(! $u->isAdmin() && $u->role?->value === 'driver', fn ($q) => $q->where('driver_id', $u->id))
            ->orderBy('number')->get();

        return view('livewire.driver.today', ['manifests' => $manifests]);
    }
}
