<?php

namespace App\Delivery;

use App\Exceptions\WorkflowException;
use App\Models\Customer;
use App\Models\DeliverySignature;
use App\Models\SalesOrder;
use App\Services\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 15: any delivery / pickup / counter / van sale containing a regulated product needs a captured signature,
 * typed printed name and the acknowledgments below. Text is snapshotted on the signature row.
 */
class SignatureService
{
    public const AGE_TEXT = 'I warrant that I am 21 years of age or older.';

    /** Terms that mean "paid at time of sale" (not charged to the account). */
    public const PAID_TERMS = ['cod', 'cash', 'prepaid', 'paid', 'due on receipt', 'cia', 'cash in advance'];

    public function requiresSignature(SalesOrder|iterable $orderOrProducts): bool
    {
        $products = $orderOrProducts instanceof SalesOrder ? $orderOrProducts->lines()->with('product')->get()->pluck('product') : collect($orderOrProducts);

        return $products->filter()->contains(fn ($p) => $p->tax_class?->isRegulated());
    }

    /** requiresSignature + the (always-on in production) erp.require_signature switch. */
    public function required(SalesOrder|iterable $orderOrProducts): bool
    {
        return config('erp.require_signature', true) && $this->requiresSignature($orderOrProducts);
    }

    public static function accountText(string $customer, string $total): string
    {
        return "I acknowledge this charge to the account of {$customer} for \$".number_format((float) $total, 2).' and I warrant that I am authorized to make this purchase on its behalf.';
    }

    /** Whether a delivery/pickup is charged to the customer's account (terms, not paid at time). */
    public function isAccountCharge(SalesOrder $so): bool
    {
        if (Money::of($so->total)->minus(Money::of($so->amount_paid))->isLessThanOrEqualTo(0)) {
            return false;
        }

        return ! self::paidTerms($so->customer);
    }

    public static function paidTerms(?Customer $c): bool
    {
        return in_array(strtolower(trim((string) $c?->terms)), self::PAID_TERMS, true);
    }

    /** @return list<string> */
    public function acknowledgments(string $customerName, string $total, bool $accountCharge): array
    {
        return $accountCharge ? [self::AGE_TEXT, self::accountText($customerName, $total)] : [self::AGE_TEXT];
    }

    /**
     * @param  array{image:string, name:string, accepted:array<int,bool|string>, lat?:mixed, lng?:mixed}  $input
     * @param  bool|null  $accountCharge  null = derive from order (delivery/pickup); POS/van pass tender === 'account'
     */
    public function capture(SalesOrder $so, array $input, string $context, ?int $stopId = null, ?bool $accountCharge = null): DeliverySignature
    {
        $so->loadMissing('customer');
        $account = $accountCharge ?? $this->isAccountCharge($so);
        $acks = $this->acknowledgments($so->customer->name, (string) $so->total, $account);
        $name = trim((string) ($input['name'] ?? ''));
        $errors = [];
        if (mb_strlen($name) < 2) {
            $errors['signer_name'] = 'Type the signer\'s printed name.';
        }
        foreach ($acks as $i => $_) {
            if (empty($input['accepted'][$i])) {
                $errors["ack.$i"] = 'Every acknowledgment must be checked.';
            }
        }
        $png = self::decodePng((string) ($input['image'] ?? ''));
        if ($png === null) {
            $errors['signature'] = 'A signature is required.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $path = 'signatures/'.$so->id.'-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(4)).'.png';
        Storage::disk('local')->put($path, $png);
        $num = fn ($v) => is_numeric($v) ? round((float) $v, 7) : null;

        return DeliverySignature::create([
            'sales_order_id' => $so->id, 'delivery_stop_id' => $stopId, 'context' => $context,
            'signer_name' => mb_substr($name, 0, 120), 'image_path' => $path, 'acknowledgments' => $acks,
            'account_charge' => $account, 'order_total' => $so->total, 'user_id' => auth()->id(),
            'ip' => request()?->ip(), 'latitude' => $num($input['lat'] ?? null), 'longitude' => $num($input['lng'] ?? null),
            'signed_at' => now(),
        ]);
    }

    /** Blocks completion of a regulated order without a signature taken for its current total. */
    public function assertSigned(SalesOrder $so): void
    {
        if (! $this->required($so)) {
            return;
        }
        $sig = DeliverySignature::where('sales_order_id', $so->id)->latest('id')->first();
        if (! $sig) {
            throw new WorkflowException("Order {$so->number} contains regulated products: a signature with the 21+ acknowledgment is required before delivery or pickup.");
        }
    }

    /** Accepts a data:image/png;base64 URI from the canvas; rejects blanks/oversize. */
    public static function decodePng(string $dataUri): ?string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUri, $m)) {
            return null;
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) < 60 || strlen($bin) > 2_000_000 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }

        return $bin;
    }
}
