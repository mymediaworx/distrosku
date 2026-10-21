<?php

namespace App\Services;

use App\Models\ComplianceRuleVersion;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides whether a product may be sold to a customer ship-to on a date.
 * Rules come from compliance_rule_versions (fed later by the Rules Hub).
 */
class ComplianceChecker
{
    /** @return array<int, string> reasons; empty = allowed */
    public function checkLine(Product $product, Customer $customer, ShipTo $shipTo, CarbonInterface|string|null $date = null, bool $withRegistry = true): array
    {
        $date = $date ? Carbon::parse($date)->startOfDay() : Carbon::today();
        $state = strtoupper($shipTo->state);
        $home = strtoupper(config('erp.home_state', 'KY'));
        $class = $product->tax_class?->value;
        $reasons = [];

        $pactCat = \App\Compliance\PactCategory::of($product);
        if (($product->pact_covered || ($pactCat && $pactCat !== 'other' && \App\Models\Setting::get('pact_interstate_enabled'))) && $state !== $home) {
            $d = $date->toDateString();
            if (! \App\Models\Setting::get('pact_interstate_enabled')) {
                $reasons[] = "PACT Act item ({$class}) cannot ship outside {$home} (ship-to state {$state}).";
            } elseif (! \App\Models\PactRegistration::current('US', $d) || ! \App\Models\PactRegistration::current($state, $d)) {
                $reasons[] = "PACT Act item ({$class}): no current PACT registration with ATF and {$state} on file (15 U.S.C. 376(a)(1)).";
            }
        }
        // 0.18: MSA directory, hemp/THC, FDA vape status, vape directories etc. run through the eligibility registry.
        if ($withRegistry) {
            array_push($reasons, ...app(\App\Compliance\Eligibility::class)->evaluate($product, $shipTo, $date)['block']);
        }

        foreach ($this->activeRules($state, $date) as $rule) {
            if ($rule->tax_class && $rule->tax_class->value !== $class) {
                continue;
            }
            if ($rule->hemp_type && $rule->hemp_type !== $product->hemp_type) {
                continue;
            }
            $what = trim(($class ?? '').($rule->hemp_type ? "/{$rule->hemp_type}" : ''), '/');
            $cite = $rule->source ? " [{$rule->source}]" : '';

            switch ($rule->rule) {
                case 'ban':
                    if ($rule->banned && ($rule->tax_class || $rule->hemp_type)) {
                        $reasons[] = "{$what} is banned in {$state} from {$rule->effective_from->toDateString()}{$cite}.";
                    }
                    break;
                case 'thc_limit':
                    if ($rule->max_thc_mg !== null && $product->thc_mg !== null
                        && Money::of($product->thc_mg)->isGreaterThan(Money::of($rule->max_thc_mg))) {
                        $reasons[] = "THC {$product->thc_mg} mg exceeds {$state} limit of {$rule->max_thc_mg} mg{$cite}.";
                    }
                    break;
                case 'license_required':
                    if ($rule->license_type && ! $this->hasLicense($customer, $shipTo, $rule->license_type, $state, $date)) {
                        $reasons[] = "Customer needs a current {$state} {$rule->license_type} license for {$what}{$cite}.";
                    }
                    break;
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @return array<string, array<int, string>> keyed by SKU, only blocked lines */
    public function checkOrder(SalesOrder $order, bool $withRegistry = true): array
    {
        $order->loadMissing('lines.product', 'customer', 'shipTo');
        $loc = $order->location(); // 0.19.6: counter pickup = the selling warehouse
        $out = [];
        foreach ($order->lines as $line) {
            $r = $this->checkLine($line->product, $order->customer, $loc, $order->order_date, $withRegistry);
            if ($r) {
                $out[$line->product->sku] = $r;
            }
        }

        return $out;
    }

    /** @return Collection<int, ComplianceRuleVersion> */
    public function activeRules(string $state, CarbonInterface $date): Collection
    {
        return ComplianceRuleVersion::query()
            ->where('jurisdiction', $state)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->get();
    }

    private function hasLicense(Customer $customer, ShipTo $shipTo, string $type, string $state, CarbonInterface $date): bool
    {
        return $customer->licenses()
            ->where('type', $type)
            ->where('state', $state)
            ->where(fn ($q) => $q->whereNull('ship_to_id')->orWhere('ship_to_id', $shipTo->id))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $date))
            ->exists();
    }
}
