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
            ->navigationGroups(['Sales', 'Catalog', 'Customers', 'Inventory', 'Purchasing', 'Accounting', 'Reports', 'Tax Reports', 'Compliance', 'Settings', 'Admin', 'Platform'])
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn () => Company::isSuspended()
                ? new HtmlString('<div data-test="suspended" style="border:1px solid #e5a3a3;background:#fff4f4;padding:.75rem;border-radius:.5rem;margin-bottom:1rem">'
                    .'<strong>This account is suspended.</strong> Sign-in is disabled for everyone except BrandWorx support. Please contact BrandWorx.</div>')
                : '')
            ->userMenuItems([
                MenuItem::make()->label('Change password')->icon('heroicon-o-key')->url(fn () => ChangePassword::getUrl()),
            ])
            ->navigationItems([
                NavigationItem::make('Warehouse scanner')->url('/warehouse')->icon('heroicon-o-qr-code')->group('Inventory')->sort(90)
                    ->visible(fn () => (bool) auth()->user()?->canModule('warehouse_scan')),
            ])
            ->renderHook(PanelsRenderHook::FOOTER, fn () => view('partials.powered-by'))
            ->colors([
                'primary' => Color::Amber,
            ])
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
