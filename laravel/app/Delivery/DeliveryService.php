<?php

namespace App\Delivery;

use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Models\DeliveryStop;
use App\Services\Money;
use App\Services\SalesOrderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Driver completes a stop: delivered | partial (reduced qtys) | refused (status only; office re-delivers or cancels,
 * no credit memo). Regulated orders need the signature (captured in the same transaction).
 */
class DeliveryService
{
    public function __construct(private SignatureService $sig, private SalesOrderService $orders) {}

    /**
     * @param  array<int|string, mixed>  $deliveredQty  sales_order_line_id => qty (partial only)
     * @param  array|null  $signature  ['image','name','accepted','lat','lng']
     */
    public function complete(DeliveryStop $stop, string $status, array $deliveredQty = [], ?string $notes = null,
        ?UploadedFile $photo = null, ?array $signature = null, mixed $lat = null, mixed $lng = null): DeliveryStop
    {
        if (! in_array($status, ['delivered', 'partial', 'refused'], true)) {
            throw new WorkflowException('Unknown stop status.');
        }
        if ($stop->status !== 'pending') {
            throw new WorkflowException("This stop is already {$stop->status}.");
        }
        $so = $stop->salesOrder;
        if ($status !== 'refused' && ! in_array($so->status, [S::Confirmed, S::Picked], true)) {
            throw new WorkflowException("Order {$so->number} is {$so->status->value}; it cannot be delivered.");
        }

        return DB::transaction(function () use ($stop, $status, $deliveredQty, $notes, $photo, $signature, $lat, $lng, $so) {
            $extra = ['notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : $stop->notes, 'status_by' => auth()->id(),
                'latitude' => is_numeric($lat) ? $lat : null, 'longitude' => is_numeric($lng) ? $lng : null];
            if ($photo) {
                $extra['photo_path'] = $photo->store('delivery-photos', 'local');
            }
            if ($status === 'refused') {
                $stop->update($extra + ['status' => 'refused', 'delivered_at' => now()]);

                return $stop;
            }
            if ($status === 'partial') {
                $changed = false;
                foreach ($so->lines()->with('product')->get() as $line) {
                    if (! array_key_exists($line->id, $deliveredQty) || $deliveredQty[$line->id] === '' || $deliveredQty[$line->id] === null) {
                        continue;
                    }
                    $q = Money::of($deliveredQty[$line->id]);
                    if ($q->isLessThan(Money::of($line->qty))) {
                        $this->orders->reduceLine($so->fresh(), $line, $q);
                        $changed = true;
                    }
                }
                if (! $changed) {
                    throw new WorkflowException('Partial delivery: enter a lower quantity on at least one line.');
                }
                if ($so->lines()->count() === 0) {
                    throw new WorkflowException('Nothing left to deliver; mark the stop refused instead.');
                }
                $so->refresh();
            }
            if ($this->sig->required($so)) {
                if (! $signature) {
                    throw new WorkflowException('Signature required: this order contains regulated products.');
                }
                $this->sig->capture($so, $signature + ['lat' => $lat, 'lng' => $lng], 'delivery', $stop->id);
            }
            $stop->update($extra + ['status' => $status, 'received_by' => $signature['name'] ?? $stop->received_by]); // saved hook delivers

            return $stop->fresh();
        });
    }
}
