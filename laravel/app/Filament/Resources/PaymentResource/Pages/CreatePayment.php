<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** 0.20 fix: a payment is never saved directly; it goes through SalesOrderService (invoice if needed, GL post, order amount_paid). */
class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    protected static ?string $title = 'Record payment';

    protected function handleRecordCreation(array $data): Model
    {
        $so = SalesOrder::findOrFail($data['sales_order_id']);
        try {
            return app(SalesOrderService::class)->settle($so, $data['method'], $data['amount'], $data['reference'] ?? null);
        } catch (\App\Exceptions\WorkflowException $e) {
            throw ValidationException::withMessages(['data.amount' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return PaymentResource::getUrl('index');
    }
}
