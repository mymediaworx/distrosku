<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesOrder;

/**
 * 0.20.20 (walkthrough #11): taxes shown on every invoice line.
 * A product carries a list of taxes charged ON TOP of its price: per-unit amounts ("KY excise $0.25 / unit") or a percent of the
 * unit price. Per-unit tax = sum of them; invoice line total = (unit price + tax per unit) x quantity.
 * Nothing is configured by default, so an order is unchanged until a product has a tax.
 */
class InvoiceTax
{
    public const PER_UNIT = 'per_unit';

    public const PERCENT = 'percent';

    /** Clean a taxes list: keeps rows with a name and a non-negative numeric amount. */
    public static function normalize(?array $taxes): array
    {
        $out = [];
        foreach ($taxes ?? [] as $t) {
            $name = trim((string) ($t['name'] ?? ''));
            $amount = $t['amount'] ?? null;
            if ($name === '' || ! is_numeric($amount) || (float) $amount < 0) {
                continue;
            }
            $out[] = ['name' => $name, 'basis' => ($t['basis'] ?? self::PER_UNIT) === self::PERCENT ? self::PERCENT : self::PER_UNIT, 'amount' => (string) $amount];
        }

        return $out;
    }

    /** @return array{total: string, items: list<array{name: string, per_unit: string}>}  tax charged for ONE unit at $unitPrice */
    public static function perUnit(?Product $p, mixed $unitPrice, ?\App\Models\Customer $customer = null, mixed $date = null): array
    {
        $items = [];
        $sum = Money::of(0);
        foreach (self::normalize($p?->taxes) as $t) {
            $amt = $t['basis'] === self::PERCENT
                ? Money::of($unitPrice)->multipliedBy(Money::of($t['amount']))->dividedBy(100, 4, \Brick\Math\RoundingMode::HALF_UP)
                : Money::of($t['amount'])->toScale(4, \Brick\Math\RoundingMode::HALF_UP);
            $items[] = ['name' => $t['name'], 'per_unit' => Money::s($amt)];
            $sum = $sum->plus($amt);
        }
        // #43: state sales tax (off by default) on the price plus the product's own taxes, unless the customer holds a valid certificate
        if ($p && ($st = \App\Tax\SalesTax::perUnit($customer, Money::of($unitPrice)->plus($sum), $date))) {
            $items[] = $st;
            $sum = $sum->plus(Money::of($st['per_unit']));
        }

        return ['total' => Money::s($sum), 'items' => $items];
    }

    /** Total tax on $qty units of $p at $unitPrice. */
    public static function lineTax(?Product $p, mixed $qty, mixed $unitPrice, ?\App\Models\Customer $customer = null): string
    {
        return Money::mul($qty, self::perUnit($p, $unitPrice, $customer)['total']);
    }

    /** Sum of the tax on a cart of ['product_id','qty','price'] rows (POS / van sale totals). */
    public static function cartTax(array $cart, mixed $customerId = null): string
    {
        if (! $cart) {
            return Money::s(0);
        }
        $products = Product::whereIn('id', array_column($cart, 'product_id'))->get()->keyBy('id');
        $c = $customerId ? \App\Models\Customer::find($customerId) : null; // #43: sales tax depends on the customer's certificate

        return Money::add(...array_map(fn ($l) => self::lineTax($products[$l['product_id']] ?? null, $l['qty'] ?: 0, $l['price'] ?: 0, $c), $cart));
    }

    /** Snapshot the tax on a line (qty x tax per unit, with the per-tax detail). Does not save. */
    public static function applyToLine(\App\Models\SalesOrderLine $l): void
    {
        $so = $l->salesOrder; // #43: sales tax follows the order's customer and date
        $pu = self::perUnit($l->product, $l->unit_price, $so?->customer, $so?->order_date);
        $l->tax_per_unit = $pu['total'];
        $l->tax_total = Money::mul($l->qty, $pu['total']);
        $l->tax_detail = $pu['items'];
    }

    /**
     * Invoice summary of every tax on an order: [name => amount], in first-seen order, plus the (price + tax) x qty line totals.
     *
     * @return array{by_name: array<string,string>, tax_total: string, subtotal: string, total: string}
     */
    public static function summary(SalesOrder $so): array
    {
        $by = [];
        foreach ($so->lines as $l) {
            foreach ((array) $l->tax_detail as $d) {
                $by[$d['name']] = Money::add($by[$d['name']] ?? 0, Money::mul($l->qty, $d['per_unit']));
            }
        }

        return ['by_name' => $by, 'tax_total' => Money::s($so->tax_total), 'subtotal' => Money::s($so->subtotal), 'total' => Money::s($so->total)];
    }

    /** A per-unit money amount: at least 2 decimals, up to 4 when the cents are not enough (0.125 stays 0.125). */
    public static function fmt(mixed $v): string
    {
        $s = number_format((float) Money::s($v), 4, '.', ',');
        $s = rtrim($s, '0');
        $dec = strlen(substr($s, (int) strrpos($s, '.') + 1));

        return $dec >= 2 ? $s : $s.str_repeat('0', 2 - $dec);
    }

    /** Invoice line total: (unit price + tax per unit) x quantity. */
    public static function lineWithTax(\App\Models\SalesOrderLine $l): string
    {
        return Money::mul($l->qty, Money::add($l->unit_price, $l->tax_per_unit));
    }
}
