<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Reports\SalesByCustomer;
use App\Filament\Resources;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Route;
use Throwable;

/** Phase 15: icon launcher ("Modules" home). One large tile per module; only tiles the user's role can open. */
class Modules extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = -10;

    protected static ?string $title = 'Modules';

    protected static ?string $slug = 'modules';

    protected static string $view = 'filament.pages.modules';

    /** [label, heroicon, resource/page class OR route name OR url, extra access check]. */
    public static function definitions(): array
    {
        $can = fn (string $m, string $a = 'view') => fn () => (bool) auth()->user()?->canModule($m, $a);

        return [
            ['Sales Orders', 'heroicon-o-shopping-cart', Resources\SalesOrderResource::class],
            ['Customers', 'heroicon-o-user-group', Resources\CustomerResource::class],
            ['Products', 'heroicon-o-cube', Resources\ProductResource::class],
            ['Inventory', 'heroicon-o-archive-box', Resources\InventoryResource::class],
            ['Purchasing', 'heroicon-o-clipboard-document-list', Resources\PurchaseOrderResource::class],
            ['Vendors', 'heroicon-o-building-storefront', Resources\VendorResource::class],
            ['Warehouse', 'heroicon-o-qr-code', 'wh.hub', $can('warehouse_scan')],
            ['Delivery', 'heroicon-o-truck', Resources\DeliveryManifestResource::class],
            ['Driver app', 'heroicon-o-map-pin', 'driver.today', $can('driver_app')],
            ['Van sales', 'heroicon-o-banknotes', 'driver.van', $can('van_sales', 'manage')],
            ['POS', 'heroicon-o-calculator', 'pos', $can('pos', 'manage')],
            ['Payments', 'heroicon-o-credit-card', Resources\PaymentResource::class],
            ['Accounting', 'heroicon-o-book-open', Resources\JournalResource::class],
            ['Bills (AP)', 'heroicon-o-document-text', Resources\VendorBillResource::class],
            ['Pricing', 'heroicon-o-tag', Resources\PriceRuleResource::class],
            ['Promotions', 'heroicon-o-gift', Resources\PromotionResource::class],
            ['Reports', 'heroicon-o-chart-bar', SalesByCustomer::class],
            ['Compliance', 'heroicon-o-shield-check', Resources\ComplianceRuleVersionResource::class],
            ['Tax filings', 'heroicon-o-receipt-percent', Resources\TaxFilingResource::class],
            ['MSA weekly', 'heroicon-o-document-arrow-up', MsaWeekly::class],
            ['PACT reports', 'heroicon-o-flag', Resources\PactReportResource::class],
            ['Imports', 'heroicon-o-arrow-up-tray', Imports::class],
            ['Users', 'heroicon-o-users', Resources\UserResource::class],
            ['Settings', 'heroicon-o-cog-6-tooth', Settings::class],
        ];
    }

    /** @return list<array{label:string, icon:string, url:string}> */
    public static function tiles(): array
    {
        $out = [];
        foreach (static::definitions() as $d) {
            [$label, $icon, $target] = $d;
            $check = $d[3] ?? null;
            try {
                if (class_exists($target) && is_subclass_of($target, \Filament\Resources\Resource::class)) {
                    if (! $target::canViewAny()) {
                        continue;
                    }
                    $url = $target::getUrl('index');
                } elseif (class_exists($target) && is_subclass_of($target, Page::class)) {
                    if (! $target::canAccess()) {
                        continue;
                    }
                    $url = $target::getUrl();
                } else {
                    if ($check && ! $check()) {
                        continue;
                    }
                    $url = Route::has($target) ? route($target) : $target;
                }
            } catch (Throwable) {
                continue;
            }
            $out[] = ['label' => $label, 'icon' => $icon, 'url' => $url];
        }

        return $out;
    }

    public function isHome(): bool
    {
        return auth()->user()?->landing_page === 'modules';
    }

    public function toggleHome(): void
    {
        $u = auth()->user();
        $u->forceFill(['landing_page' => $this->isHome() ? null : 'modules'])->save();
        Notification::make()->success()->title($this->isHome() ? 'Modules is now your home page after sign-in.' : 'Dashboard is your home page again.')->send();
    }
}
