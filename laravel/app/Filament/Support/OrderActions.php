<?php

namespace App\Filament\Support;

use App\Credit\CreditLimit;
use App\Enums\SalesOrderStatus as S;
use App\Filament\Pages\CardPayments;
use App\Models\SalesOrder;
use App\Services\Money;
use App\Services\SalesOrderService;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Throwable;

/**
 * 0.20 order-to-cash actions, shared by the Sales Orders list (row actions), the order page (header buttons) and Receivables.
 * Pass the Action class: \Filament\Tables\Actions\Action::class or \Filament\Actions\Action::class.
 */
class OrderActions
{
    public static function canManage(): bool
    {
        return (bool) auth()->user()?->canModule('sales_orders', 'manage');
    }

    public static function hasBalance(SalesOrder $r): bool
    {
        return in_array($r->status, [S::Delivered, S::Invoiced], true) && Money::of($r->balance())->isPositive();
    }

    /** Payment form: amount (defaults to balance, partial allowed), method, check # (required for checks), admin credit override reason for on account. */
    public static function paymentFields(bool $allowOnAccount = true): array
    {
        $methods = SalesOrderService::METHODS;
        if (! $allowOnAccount) {
            unset($methods['on_account']);
        }

        return [
            Forms\Components\Select::make('method')->label('Method')->options($methods)->default('cash')->required()->live()->selectablePlaceholder(false),
            Forms\Components\TextInput::make('amount')->numeric()->required()->minValue(0.01)->prefix('$')
                ->default(fn ($record) => $record ? number_format((float) $record->balance(), 2, '.', '') : null)
                ->visible(fn (Get $get) => $get('method') !== 'on_account')
                ->helperText('Defaults to the open balance; enter less for a partial payment.'),
            Forms\Components\TextInput::make('reference')->label(fn (Get $get) => $get('method') === 'check' ? 'Check #' : 'Reference')
                ->required(fn (Get $get) => $get('method') === 'check')->maxLength(100)
                ->visible(fn (Get $get) => $get('method') !== 'on_account'),
            Forms\Components\Placeholder::make('credit')->label('Credit')
                ->visible(fn (Get $get) => $get('method') === 'on_account')
                ->content(fn ($record) => $record ? CreditLimit::summary($record->customer) : ''),
            Forms\Components\Textarea::make('override_reason')->label('Credit limit override reason (owner/admin)')
                ->helperText('Only needed when this puts the customer over their credit limit. Recorded in the Activity log.')
                ->visible(fn (Get $get) => $get('method') === 'on_account' && auth()->user()?->isAdmin())->maxLength(500),
        ];
    }

    private static function run(callable $fn, $livewire = null): void
    {
        try {
            $msg = $fn();
            Notification::make()->success()->title(is_string($msg) ? $msg : 'Done')->send();
            if ($livewire instanceof \Filament\Resources\Pages\EditRecord) {
                $livewire->redirect(\App\Filament\Resources\SalesOrderResource::getUrl('edit', ['record' => $livewire->getRecord()]));
            }
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
        }
    }

    public static function payMessage(?\App\Models\Payment $p, SalesOrder $so): string
    {
        $so->refresh();
        if (! $p) {
            return "{$so->number} invoiced and left on account (\${$so->balance()} open).";
        }

        return 'Payment $'.number_format((float) $p->amount, 2)." recorded on {$so->number}. ".(Money::of($so->balance())->isPositive() ? "Still open: \${$so->balance()}." : 'Paid in full.');
    }

    public static function confirm(string $cls)
    {
        return $cls::make('confirm')->label('Confirm')->icon('heroicon-o-check')->requiresConfirmation()
            ->visible(fn (SalesOrder $record) => $record->status === S::Draft && self::canManage())
            ->action(fn (SalesOrder $record, $livewire) => self::run(fn () => [app(SalesOrderService::class)->confirm($record), 'Confirmed and stock reserved.'][1], $livewire));
    }

    public static function deliver(string $cls)
    {
        return $cls::make('deliver')->label('Deliver & invoice')->icon('heroicon-o-truck')->requiresConfirmation()
            ->modalDescription(fn (SalesOrder $record) => 'Stock goes out and the invoice is posted in one step. Order total $'.number_format((float) $record->total, 2).'.')
            ->visible(fn (SalesOrder $record) => in_array($record->status, [S::Confirmed, S::Picked], true) && self::canManage())
            ->action(fn (SalesOrder $record, $livewire) => self::run(fn () => [app(SalesOrderService::class)->deliver($record), "Delivered and invoiced ({$record->number})."][1], $livewire));
    }

    public static function deliverCollect(string $cls)
    {
        return $cls::make('deliverCollect')->label('Deliver, invoice & collect')->icon('heroicon-o-banknotes')->color('success')
            ->modalDescription(fn (SalesOrder $record) => 'Cash on delivery: stock out, invoice and payment in one step. Order total $'.number_format((float) $record->total, 2).', balance due $'.number_format((float) $record->balance(), 2).'.')
            ->visible(fn (SalesOrder $record) => in_array($record->status, [S::Confirmed, S::Picked], true) && self::canManage())
            ->fillForm(fn (SalesOrder $record) => ['method' => 'cash', 'amount' => number_format((float) $record->balance(), 2, '.', '')])
            ->form(self::paymentFields())
            ->action(fn (SalesOrder $record, array $data, $livewire) => self::run(function () use ($record, $data) {
                $p = app(SalesOrderService::class)->deliverAndCollect($record, $data['method'], $data['amount'] ?? null, $data['reference'] ?? null, $data['override_reason'] ?? null);

                return 'Delivered and invoiced. '.self::payMessage($p, $record);
            }, $livewire));
    }

    public static function invoice(string $cls)
    {
        return $cls::make('invoice')->label('Invoice')->icon('heroicon-o-document-text')->requiresConfirmation()
            ->visible(fn (SalesOrder $record) => $record->status === S::Delivered && self::canManage())
            ->action(fn (SalesOrder $record, $livewire) => self::run(fn () => [app(SalesOrderService::class)->invoice($record), 'Invoiced and posted to ledger.'][1], $livewire));
    }

    public static function pay(string $cls)
    {
        return $cls::make('pay')->label('Record payment')->icon('heroicon-o-banknotes')->color('success')
            ->visible(fn (SalesOrder $record) => self::hasBalance($record) && self::canManage())
            ->modalDescription(fn (SalesOrder $record) => ($record->status === S::Delivered ? 'This order is invoiced first. ' : '').'Open balance $'.number_format((float) $record->balance(), 2).'.')
            ->fillForm(fn (SalesOrder $record) => ['method' => 'cash', 'amount' => number_format((float) $record->balance(), 2, '.', '')])
            ->form(self::paymentFields())
            ->action(fn (SalesOrder $record, array $data, $livewire) => self::run(function () use ($record, $data) {
                $p = app(SalesOrderService::class)->settle($record, $data['method'], $data['amount'] ?? null, $data['reference'] ?? null, $data['override_reason'] ?? null);

                return self::payMessage($p, $record);
            }, $livewire));
    }

    public static function chargeCard(string $cls)
    {
        return $cls::make('chargeCard')->label('Charge card / ACH')->icon('heroicon-o-credit-card')
            ->visible(fn (SalesOrder $record) => $record->status === S::Invoiced && Money::of($record->balance())->isPositive() && auth()->user()?->canModule('card_payments', 'manage'))
            ->url(fn (SalesOrder $record) => CardPayments::getUrl(['order' => $record->id]));
    }

    /** All order-to-cash steps in click order. */
    public static function flow(string $cls): array
    {
        return [self::confirm($cls), self::deliver($cls), self::deliverCollect($cls), self::invoice($cls), self::pay($cls), self::chargeCard($cls)];
    }
}
