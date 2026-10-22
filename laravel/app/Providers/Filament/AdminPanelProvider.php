<?php

namespace App\Providers\Filament;

use App\Filament\Pages\ChangePassword;
use App\Http\Middleware\BlockWhenSuspended;
use App\Http\Middleware\ForcePasswordChange;
use App\Models\Company;
use App\Security\PasswordAdmin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->when(PasswordAdmin::mailConfigured(), fn (Panel $p) => $p->passwordReset()) // forgot password only when mail works
            ->brandName(fn () => Company::current()->name ?: config('app.name'))
            ->brandLogo(fn () => Company::current()->logoUrl())
            ->brandLogoHeight('2.5rem')
            ->databaseNotifications() // 0.20.1 bell: receiving-team inbound PO alerts
            ->databaseNotificationsPolling('60s')
            ->navigationGroups(\App\Navigation\NavOrder::GROUPS) // 0.20.1 order
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn () => Company::isSuspended()
                ? new HtmlString('<div data-test="suspended" style="border:1px solid #e5a3a3;background:#fff4f4;padding:.75rem;border-radius:.5rem;margin-bottom:1rem">'
                    .'<strong>This account is suspended.</strong> Sign-in is disabled for everyone except BrandWorx support. Please contact BrandWorx.</div>')
                : '')
            ->userMenuItems([
                MenuItem::make()->label('Change password')->icon('heroicon-o-key')->url(fn () => ChangePassword::getUrl()),
            ])
            ->navigationItems([
                NavigationItem::make('POS')->url('/pos')->icon('heroicon-o-calculator')->group('Sales')->sort(0)
                    ->visible(fn () => (bool) auth()->user()?->canModule('pos', 'manage')),
                NavigationItem::make('Warehouse scanner')->url('/warehouse')->icon('heroicon-o-qr-code')->group('Warehouse')->sort(1)
                    ->visible(fn () => (bool) auth()->user()?->canModule('warehouse_scan')),
                NavigationItem::make('Driver app')->url('/driver')->icon('heroicon-o-map-pin')->group('Delivery')->sort(3)
                    ->visible(fn () => (bool) auth()->user()?->canModule('driver_app')),
                NavigationItem::make('Van sales')->url('/driver/van')->icon('heroicon-o-banknotes')->group('Delivery')->sort(4)
                    ->visible(fn () => (bool) auth()->user()?->canModule('van_sales', 'manage')),
            ])
            ->renderHook(PanelsRenderHook::FOOTER, fn () => view('partials.powered-by'))
            ->colors([
                'primary' => Color::Amber,
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Orange,
                'danger' => Color::Rose,
            ])
            ->font('Inter')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(\Filament\Support\Enums\MaxWidth::Full)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.admin-polish'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                BlockWhenSuspended::class, // before Authenticate: clear message instead of a bare 403
            ])
            ->authMiddleware([
                Authenticate::class,
                ForcePasswordChange::class,
            ]);
    }
}
