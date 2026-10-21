<?php

namespace App\Livewire\Driver;

use App\Delivery\SignatureService;
use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Livewire\Concerns\SignaturePad;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/** Will-call / counter pickup of an office order: customer signs on the tablet, then the order is delivered. */
#[Layout('layouts.driver')]
#[Title('Pickup signature')]
class PickupSign extends Component
{
    use SignaturePad;

    public SalesOrder $so;

    public ?string $ok = null;

    public ?string $err = null;

    public function mount(SalesOrder $so): void
    {
        abort_unless(auth()->user()?->canModule('pickup_signature', 'manage'), 403);
        $this->so = $so;
    }

    public function complete(SignatureService $sig, SalesOrderService $svc): void
    {
        abort_unless(auth()->user()?->canModule('pickup_signature', 'manage'), 403);
        $this->ok = $this->err = null;
        try {
            DB::transaction(function () use ($sig, $svc) {
                $so = $this->so->fresh();
                if (! in_array($so->status, [S::Confirmed, S::Picked], true)) {
                    throw new WorkflowException("Order {$so->number} is {$so->status->value}.");
                }
                if ($sig->required($so)) {
                    $sig->capture($so, $this->sigInput() ?? [], 'pickup');
                }
                $svc->deliver($so);
            });
            $this->so->refresh();
            $this->resetSignature();
            $this->ok = "Order {$this->so->number} picked up.";
        } catch (ValidationException $e) {
            $this->err = implode(' ', array_unique($e->validator->errors()->all()));
        } catch (Throwable $e) {
            $this->err = $e->getMessage();
        }
    }

    public function render(SignatureService $sig)
    {
        $this->so->load('lines.product', 'customer');
        $needs = $sig->requiresSignature($this->so);

        return view('livewire.driver.pickup', ['needsSig' => $needs,
            'acks' => $needs ? $sig->acknowledgments($this->so->customer->name, (string) $this->so->total, $sig->isAccountCharge($this->so)) : []]);
    }
}
