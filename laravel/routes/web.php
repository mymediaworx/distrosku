<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\PortalDocumentsController;
use App\Http\Middleware\BlockWhenSuspended;
use App\Http\Middleware\PortalAuthenticate;
use App\Livewire\CounterPos;
use App\Livewire\Portal;
use App\Livewire\QuickTools;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// 0.14.1: /pos accepts a password login or a POS PIN session (PosAccess); PIN sessions never reach /admin.
Route::middleware([\App\Http\Middleware\PosAccess::class])->group(function () {
    Route::get('/pos', CounterPos::class)->name('pos');
    Route::get('/pos/receipt/{so}', [\App\Http\Controllers\PosReceiptController::class, 'show'])->name('pos.receipt'); // #27
    Route::post('/pos/offline/sync', [\App\Http\Controllers\OfflineSaleController::class, 'sync'])->middleware('throttle:60,1')->name('pos.offline.sync'); // #26
    Route::get('/pos/offline', [\App\Http\Controllers\OfflinePosController::class, 'page'])->name('pos.offline'); // #26 device side
    Route::get('/pos/offline/catalog', [\App\Http\Controllers\OfflinePosController::class, 'catalog'])->middleware('throttle:30,1')->name('pos.offline.catalog');
});
Route::middleware(['throttle:60,1'])->get('/pos/lock', \App\Livewire\PosLock::class)->name('pos.lock');
Route::middleware(['auth', BlockWhenSuspended::class, 'can:use-pos'])->get('/pos/pin', \App\Livewire\PosPinSetup::class)->name('pos.pin');

// Phase 9 warehouse scanning (scanner-friendly screens) + printable pick / packing lists.
Route::middleware(['auth', BlockWhenSuspended::class])->prefix('warehouse')->name('wh.')->group(function () {
    Route::get('/', \App\Livewire\Warehouse\Hub::class)->name('hub');
    Route::get('/receive/{po}', \App\Livewire\Warehouse\Receive::class)->name('receive');
    Route::get('/putaway', \App\Livewire\Warehouse\Putaway::class)->name('putaway');
    Route::get('/pick/{so}', \App\Livewire\Warehouse\Pick::class)->name('pick');
    Route::get('/pack/{so}', \App\Livewire\Warehouse\Pack::class)->name('pack');
    Route::get('/count/{count}', \App\Livewire\Warehouse\Count::class)->name('count');
    Route::get('/print/count/{count}', [\App\Http\Controllers\WarehousePrintController::class, 'countSheet'])->name('print.count');
    Route::get('/print/label/{package}', [\App\Http\Controllers\WarehousePrintController::class, 'label'])->name('print.label');
    Route::get('/print/bin-labels', [\App\Http\Controllers\WarehousePrintController::class, 'binLabels'])->name('print.bins');
    Route::get('/recall.csv', [\App\Http\Controllers\WarehousePrintController::class, 'recall'])->name('recall.csv');
    Route::get('/trace.csv', [\App\Http\Controllers\WarehousePrintController::class, 'trace'])->name('trace.csv'); // 0.20.15 (#36)
    Route::get('/print/pick/{so}', [\App\Http\Controllers\WarehousePrintController::class, 'pick'])->name('print.pick');
    Route::get('/print/pack/{so}', [\App\Http\Controllers\WarehousePrintController::class, 'pack'])->name('print.pack');
});

// Phase 15 driver phone screen, van sales, will-call pickup signature (mobile/tablet).
Route::middleware(['auth', BlockWhenSuspended::class])->prefix('driver')->name('driver.')->group(function () {
    Route::get('/', \App\Livewire\Driver\Today::class)->name('today');
    Route::get('/stop/{stop}', \App\Livewire\Driver\StopScreen::class)->name('stop');
    Route::get('/van', \App\Livewire\Driver\VanSale::class)->name('van');
    Route::get('/pickup/{so}', \App\Livewire\Driver\PickupSign::class)->name('pickup');
    Route::get('/stop/{stop}/photo', [\App\Http\Controllers\DeliveryDocsController::class, 'photo'])->name('photo');
});

Route::middleware(['auth', BlockWhenSuspended::class])->prefix('catalog')->group(function () {
    Route::get('/labels', [CatalogController::class, 'labels'])->name('catalog.labels');
    Route::get('/export.csv', [CatalogController::class, 'csv'])->name('catalog.csv');
    Route::get('/export.pdf', [CatalogController::class, 'pdf'])->name('catalog.pdf');
});

// Employee Quick Tools: no login, company token link. Rate-limited (see AppServiceProvider 'quick-tools').
Route::middleware('throttle:quick-tools')->group(function () {
    Route::get('/q/{token}', QuickTools::class)->where('token', '[A-Fa-f0-9]{16,64}')->name('quick-tools');
    Route::get('/q/{token}/labels', [CatalogController::class, 'labels'])->where('token', '[A-Fa-f0-9]{16,64}')->name('quick-tools.labels');
});

// 0.20.17: Brand + Quick tools moved into Company Settings; the old address still works for bookmarks.
Route::middleware(['auth', BlockWhenSuspended::class])->get('/admin/branding', function () {
    abort_unless(auth()->user()?->canModule('company', 'manage'), 403);

    return new \Illuminate\Http\RedirectResponse(\App\Filament\Pages\Settings::getUrl());
});

// 0.20.17: Counter Pickup Board TV page (full screen, no navigation, auto-refresh)
Route::middleware(['auth', BlockWhenSuspended::class])->get('/board/pickup', \App\Http\Controllers\PickupBoardTvController::class)->name('pickup.tv');

// Staff: invoice / statement PDFs
Route::middleware(['auth', BlockWhenSuspended::class])->group(function () {
    Route::get('/admin-docs/invoice/{so}/view', [PortalDocumentsController::class, 'adminInvoiceView'])->name('docs.invoice.view');
    Route::get('/admin-docs/invoice/{so}.pdf', [PortalDocumentsController::class, 'adminInvoice'])->name('docs.invoice');
    Route::get('/admin-docs/delivery-proof/{so}.pdf', [\App\Http\Controllers\DeliveryDocsController::class, 'proof'])->name('docs.delivery-proof');
    Route::get('/admin-docs/imports/template/{type}.{format}', [\App\Http\Controllers\ImportDownloadsController::class, 'template'])->name('imports.template');
    Route::get('/admin-docs/imports/{batch}/errors.csv', [\App\Http\Controllers\ImportDownloadsController::class, 'errors'])->name('imports.errors');
    Route::get('/admin-docs/statement/{customer}.pdf', [PortalDocumentsController::class, 'adminStatement'])->name('docs.statement');
});

// B2B customer portal (guard "portal"). No emails: contacts set their password from an admin-issued one-time link.
Route::prefix('portal')->name('portal.')->middleware(\App\Http\Middleware\PortalEnabled::class)->group(function () {
    Route::middleware('throttle:portal-public')->group(function () {
        Route::get('/login', Portal\Login::class)->name('login');
        Route::get('/set-password/{token}', Portal\SetPassword::class)->where('token', '[A-Za-z0-9]{48}')->name('set-password');
    });
    Route::middleware([PortalAuthenticate::class, 'throttle:portal'])->group(function () {
        Route::get('/', Portal\Catalog::class)->name('catalog');
        Route::get('/orders', Portal\Orders::class)->name('orders');
        Route::get('/pay', Portal\Pay::class)->name('pay');
        Route::get('/invoice/{so}.pdf', [PortalDocumentsController::class, 'portalInvoice'])->name('invoice');
        Route::get('/statement.pdf', [PortalDocumentsController::class, 'portalStatement'])->name('statement');
        Route::post('/logout', function () {
            Auth::guard('portal')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('portal.login');
        })->name('logout');
    });
});

Route::middleware(['auth', BlockWhenSuspended::class])->prefix('compliance/pact')->name('pact.')->group(function () {
    Route::get('/report/{report}', [\App\Http\Controllers\PactController::class, 'report'])->name('report');
    Route::get('/registration/{registration}', [\App\Http\Controllers\PactController::class, 'registration'])->name('registration');
});


