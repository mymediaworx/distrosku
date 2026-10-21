<?php

namespace App\Livewire\Portal;

use App\Exceptions\WorkflowException;
use App\Livewire\Portal\Concerns\PortalContext;
use App\Portal\PortalService;
use App\Services\CustomerBalanceService;
use App\Services\Money;
use App\Services\PaymentService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Pay one invoice or the whole open balance (beginning balance first, then invoices oldest first) via the configured
 * gateway (Authorize.net SANDBOX). Card/bank numbers are tokenised in the browser by Accept.js; only the opaque token is posted.
 */
#[Layout('layouts.portal')]
#[Title('Pay')]
class Pay extends Component
{
    use PortalContext;

    #[Url(as: 'invoice')]
    public ?int $invoiceId = null; // null = full balance

    public array $done = [];

    public ?string $error = null;

    public function pay(string $descriptor, string $value, string $type, string $label): void
    {
        $this->reset('done', 'error');
        $key = 'portal-pay:'.$this->contact()->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->error = 'Too many payment attempts. Wait a minute.';

            return;
        }
        RateLimiter::hit($key, 60);
        $pay = app(PaymentService::class);
        $c = $this->contact()->customer;
        $invoices = app(PortalService::class)->openInvoices($this->contact());
        if ($this->invoiceId) {
            $invoices = $invoices->where('id', $this->invoiceId)->values();
            if ($invoices->isEmpty()) {
                $this->error = 'That invoice is not open.';

                return;
            }
        }
        try {
            $pm = $pay->addMethod($c, $descriptor, $value, $type, $label);
            $opening = app(CustomerBalanceService::class)->openingRemaining($c);
            if (! $this->invoiceId && Money::of($opening)->isPositive()) {
                $p = $pay->chargeOpening($c, $pm, $opening);
                $p->update(['customer_contact_id' => $this->contact()->id]);
                $this->done[] = "Beginning balance: \${$p->amount}";
            }
            foreach ($invoices as $so) {
                $p = $pay->chargeInvoice($so, $pm, $so->balance());
                $p->update(['customer_contact_id' => $this->contact()->id]);
                $this->done[] = "{$so->number}: \${$p->amount}";
            }
        } catch (WorkflowException $e) {
            $this->error = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $this->error = 'Payment could not be completed.';
        }
    }

    public function render()
    {
        $gw = app(PaymentService::class)->gateway();
        $c = $this->contact();
        $invoices = app(PortalService::class)->openInvoices($c);
        $opening = app(CustomerBalanceService::class)->openingRemaining($c->customer);
        $selected = $this->invoiceId ? $invoices->firstWhere('id', $this->invoiceId) : null;
        $amount = $selected ? $selected->balance()
            : Money::add(Money::of($opening)->isPositive() ? $opening : 0, ...($invoices->map->balance()->all() ?: [0]));

        return view('livewire.portal.pay', [
            'configured' => $gw->isConfigured(), 'problem' => $gw->configurationProblem(), 'cfg' => $gw->isConfigured() ? $gw->clientConfig() : [],
            'invoices' => $invoices, 'opening' => $opening, 'amount' => $amount, 'selected' => $selected,
        ]);
    }
}
