<?php

namespace App\Livewire\Driver;

use App\Delivery\DeliveryService;
use App\Delivery\SignatureService;
use App\Livewire\Concerns\SignaturePad;
use App\Models\DeliveryStop;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('layouts.driver')]
#[Title('Stop')]
class StopScreen extends Component
{
    use SignaturePad, WithFileUploads;

    public DeliveryStop $stop;

    public string $outcome = 'delivered';

    /** @var array<int, string> line id => delivered qty */
    public array $qty = [];

    public string $notes = '';

    public $photo = null;

    public ?string $ok = null;

    public ?string $err = null;

    public function mount(DeliveryStop $stop): void
    {
        $this->authorizeStop($stop);
        $this->stop = $stop;
        $this->notes = (string) $stop->notes;
        foreach ($stop->salesOrder->lines as $l) {
            $this->qty[$l->id] = rtrim(rtrim((string) $l->qty, '0'), '.');
        }
    }

    private function authorizeStop(DeliveryStop $stop): void
    {
        $u = auth()->user();
        abort_unless($u?->canModule('driver_app', 'manage'), 403);
        abort_if(! $u->isAdmin() && $stop->manifest->driver_id !== $u->id, 403);
    }

    public function complete(DeliveryService $svc): void
    {
        $this->authorizeStop($this->stop);
        $this->ok = $this->err = null;
        $this->validate(['photo' => 'nullable|image|max:8192', 'notes' => 'nullable|string|max:2000']);
        try {
            $svc->complete($this->stop->fresh(), $this->outcome, $this->outcome === 'partial' ? $this->qty : [], $this->notes, $this->photo,
                $this->outcome === 'refused' ? null : $this->sigInput(), $this->lat, $this->lng);
            $this->stop->refresh();
            $this->photo = null;
            $this->resetSignature();
            $this->ok = 'Stop marked '.$this->stop->status.'.';
        } catch (ValidationException $e) {
            $this->err = implode(' ', array_unique($e->validator->errors()->all()));
        } catch (Throwable $e) {
            $this->err = $e->getMessage();
        }
    }

    public function render(SignatureService $sig)
    {
        $so = $this->stop->salesOrder()->with('lines.product', 'customer', 'shipTo', 'signatures')->first();
        $needs = $sig->requiresSignature($so);

        return view('livewire.driver.stop', ['so' => $so, 'needsSig' => $needs,
            'acks' => $needs ? $sig->acknowledgments($so->customer->name, (string) $so->total, $sig->isAccountCharge($so)) : []]);
    }
}
