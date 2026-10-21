<?php

namespace App\Filament\Pages;

use App\Enums\SalesOrderStatus as S;
use App\Models\PaymentMethod;
use App\Models\SalesOrder;
use App\Services\PaymentService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Card / eCheck (ACH) payments through the configured gateway (Authorize.net sandbox).
 * Card and bank numbers are entered in un-named inputs and tokenised in the browser by Accept.js; only the opaque
 * token reaches the server, which turns it into a CIM payment profile. PAN / account numbers are never stored.
 */
class CardPayments extends Page
{
    protected static string $view = 'filament.pages.card-payments';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $slug = 'card-payments';

    protected static ?string $title = 'Card & ACH payments';

    #[Url(as: 'order')]
    public ?int $orderId = null;

    public ?int $methodId = null;

    public ?string $amount = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('card_payments', 'view');
    }

    public function mount(): void
    {
        $this->updatedOrderId();
    }

    public function updatedOrderId(): void
    {
        $so = $this->order();
        $this->amount = $so ? bcadd($so->balance(), '0', 2) : null;
        $this->methodId = $so?->customer->paymentMethods()->where('active', true)->orderByDesc('is_default')->value('id');
    }

    public function order(): ?SalesOrder
    {
        return $this->orderId ? SalesOrder::with('customer')->where('status', S::Invoiced)->find($this->orderId) : null;
    }

    public function openOrders()
    {
        return SalesOrder::with('customer')->where('status', S::Invoiced)->latest('id')->limit(200)->get()
            ->filter(fn ($so) => bccomp($so->balance(), '0', 4) > 0);
    }

    public function gatewayProblem(): ?string
    {
        return app(PaymentService::class)->gateway()->configurationProblem();
    }

    public function clientConfig(): array
    {
        $g = app(PaymentService::class)->gateway();

        return $g->isConfigured() ? $g->clientConfig() : [];
    }

    /** Called from the browser with Accept.js opaqueData. $label is like "Visa ••4242" (last 4 only). */
    public function saveMethod(string $descriptor, string $value, string $type, string $label): void
    {
        abort_unless(auth()->user()->canModule('card_payments', 'manage'), 403);
        $so = $this->order();
        if (! $so) {
            return;
        }
        try {
            $pm = app(PaymentService::class)->addMethod($so->customer, $descriptor, $value, $type, mb_substr($label, 0, 60));
            $this->methodId = $pm->id;
            Notification::make()->success()->title('Payment method saved (token only)')->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();
        }
    }

    public function charge(): void
    {
        abort_unless(auth()->user()->canModule('card_payments', 'manage'), 403);
        $so = $this->order();
        $pm = $so ? PaymentMethod::where('customer_id', $so->customer_id)->find($this->methodId) : null;
        if (! $so || ! $pm) {
            Notification::make()->warning()->title('Pick an invoice and a saved payment method')->send();

            return;
        }
        try {
            $p = app(PaymentService::class)->chargeInvoice($so, $pm, $this->amount);
            Notification::make()->success()->title("Charged {$p->amount} (transaction {$p->provider_transaction_id})")->send();
            $this->updatedOrderId();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Charge failed')->body($e->getMessage())->persistent()->send();
        }
    }
}
