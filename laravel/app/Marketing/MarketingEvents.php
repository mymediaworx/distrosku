<?php

namespace App\Marketing;

use App\Accounting\DunningService;
use App\Enums\SalesOrderStatus as S;
use App\Models\Customer;
use App\Models\DeliveryStop;
use App\Models\SalesOrder;
use App\Notify\TransactionalNotifier;
use Illuminate\Support\Carbon;

/** 0.19: hooks from ERP events to BrandWorx Marketing (sync + order events) and the transactional notifier. All no-ops when off. */
class MarketingEvents
{
    public static function register(): void
    {
        Customer::saved(function (Customer $c) {
            if ($c->wasRecentlyCreated || $c->wasChanged(['code', 'name', 'email', 'phone', 'type', 'email_opt_in', 'sms_opt_in', 'active', 'state_tax_id'])) {
                MarketingSync::queueContact($c);
            }
        });
        // 0.19.2: resale certificate number feeds a contact custom field
        \App\Models\CustomerLicense::saved(function (\App\Models\CustomerLicense $l) {
            if ($l->type === 'resale' && $l->customer) {
                MarketingSync::queueContact($l->customer);
            }
        });
        SalesOrder::updated(function (SalesOrder $so) {
            if (! $so->wasChanged('status') || ! $so->customer) {
                return;
            }
            $vars = ['order' => $so->number, 'total' => '$'.number_format((float) $so->total, 2)];
            match ($so->status) {
                S::Confirmed => [MarketingSync::queueEvent('order_placed', $so->customer, $so, [], "event:order_placed:{$so->id}"),
                    app(TransactionalNotifier::class)->notify('order_confirmed', $so->customer, $vars)],
                S::Delivered => MarketingSync::queueEvent('order_delivered', $so->customer, $so, [], "event:order_delivered:{$so->id}"),
                S::Invoiced => [MarketingSync::queueEvent('invoice_ready', $so->customer, $so, ['due' => self::due($so)], "event:invoice_ready:{$so->id}"),
                    app(TransactionalNotifier::class)->notify('invoice_emailed', $so->customer, $vars + ['due' => self::due($so)])],
                default => null,
            };
        });
        DeliveryStop::created(function (DeliveryStop $stop) {
            $so = $stop->salesOrder;
            $m = $stop->manifest ?? null;
            if ($so?->customer && $m) {
                app(TransactionalNotifier::class)->notify('delivery_eta', $so->customer, ['order' => $so->number,
                    'date' => Carbon::parse($m->delivery_date)->toDateString(), 'route' => $m->route_name ?: '-', 'total' => '$'.number_format((float) $so->total, 2)]);
            }
        });
    }

    public static function due(SalesOrder $so): string
    {
        return Carbon::parse($so->invoiced_at ?? now())->addDays(DunningService::termsDays($so->customer?->terms))->toDateString();
    }

    /** Daily: invoice due in N days / overdue events, once per invoice per kind. @return int events queued */
    public static function invoiceDates(int $dueSoonDays = 3): int
    {
        if (! MarketingSettings::enabled()) {
            return 0;
        }
        $n = 0;
        $today = Carbon::today();
        SalesOrder::with('customer')->where('status', S::Invoiced->value)->whereNotNull('invoiced_at')->chunkById(200, function ($orders) use (&$n, $today, $dueSoonDays) {
            foreach ($orders as $so) {
                if (! $so->customer || (float) $so->balance() <= 0) {
                    continue;
                }
                $due = Carbon::parse(self::due($so));
                if ($due->lt($today)) {
                    MarketingSync::queueEvent('invoice_overdue', $so->customer, $so, ['due' => $due->toDateString(), 'days_late' => (int) $due->diffInDays($today)], "event:invoice_overdue:{$so->id}");
                    $n++;
                } elseif ($due->lte($today->copy()->addDays($dueSoonDays))) {
                    MarketingSync::queueEvent('invoice_due', $so->customer, $so, ['due' => $due->toDateString()], "event:invoice_due:{$so->id}");
                    $n++;
                }
            }
        });

        return $n;
    }
}
