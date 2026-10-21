<?php

namespace App\Providers;

use App\Models\User;
use App\Payments\AuthorizeNetGateway;
use App\Payments\PaymentGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, AuthorizeNetGateway::class);
        // 0.19: transactional email/SMS. No sends unless BrandWorx Marketing is enabled (GhlNotifier also checks its own switches).
        $this->app->bind(\App\Notify\TransactionalNotifier::class, fn ($app) => \App\Marketing\MarketingSettings::enabled()
            ? $app->make(\App\Notify\GhlNotifier::class) : new \App\Notify\NullNotifier);
        $this->app->bind(\Filament\Http\Responses\Auth\Contracts\LoginResponse::class, \App\Http\Responses\LoginResponse::class);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Marketing\MarketingEvents::register(); // 0.19 BrandWorx Marketing hooks (no-ops when off)
        // dompdf resolves base_path('public') unless told otherwise; on SiteGround the web root is ../public_html
        // and laravel/public does not exist -> "Cannot resolve public path" -> every PDF 500s. Point it at the real web root.
        config(['dompdf.public_path' => public_path(), 'dompdf.options.chroot' => array_values(array_unique(array_filter([realpath(base_path()), realpath(public_path())])))]);
        \Livewire\Livewire::addPersistentMiddleware([\App\Http\Middleware\PosAccess::class]);
        Gate::define('use-pos', fn (User $u) => $u->canModule('pos', 'manage'));
        RateLimiter::for('portal-public', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('portal', fn (Request $r) => Limit::perMinute(120)->by('c'.(Auth::guard('portal')->id() ?? $r->ip())));
        \App\Tax\ReturnLock::register();
        RateLimiter::for('quick-tools', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        //
    }
}
