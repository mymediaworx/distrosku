<?php

namespace App\Filament\Pages;

use App\Compliance\KyHempRegistry;
use App\Models\Product;
use App\Models\StateRegistryEntry;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** 0.18.1: KY CHFS hemp-derived cannabinoid registry: load it, see which THC vapes are listed, link / mark listed by hand. */
class KyHempRegistryPage extends Page
{
    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'KY hemp registry';

    protected static ?string $title = 'KY hemp product registry';

    protected static ?string $slug = 'ky-hemp-registry';

    protected static string $view = 'filament.pages.ky-hemp-registry';

    public string $filter = 'unlisted';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('compliance_rules');
    }

    public static function canManage(): bool
    {
        return (bool) auth()->user()?->canModule('compliance_rules', 'manage');
    }

    public function getData(): array
    {
        $rows = KyHempRegistry::thcVapes()->map(function (Product $p) {
            $l = KyHempRegistry::currentListing($p);

            return ['product' => $p, 'listing' => $l, 'exact' => $l ? null : KyHempRegistry::exactFor($p)->first(),
                'suggest' => $l ? collect() : KyHempRegistry::suggestions($p)];
        });
        $listed = $rows->filter(fn ($r) => $r['listing'])->count();

        return ['rows' => $this->filter === 'all' ? $rows : $rows->reject(fn ($r) => $r['listing']), 'listed' => $listed, 'total' => $rows->count(),
            'entries' => StateRegistryEntry::where('registry', KyHempRegistry::REGISTRY)->whereNull('removed_on')->count(),
            'vapes' => StateRegistryEntry::where('registry', KyHempRegistry::REGISTRY)->whereNull('removed_on')->where('product_type', 'Vape')->count(),
            'lastSeen' => StateRegistryEntry::where('registry', KyHempRegistry::REGISTRY)->max('last_seen_on'), 'url' => KyHempRegistry::URL,
            'enforced' => (bool) \App\Models\EligibilityRule::where('key', 'ky_thc_vape_registry')->value('enabled')];
    }

    private function run(callable $fn, string $ok): void
    {
        abort_unless(static::canManage(), 403);
        try {
            $msg = $fn();
            Notification::make()->success()->title($ok)->body(is_string($msg) ? $msg : null)->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
        }
    }

    private static function summary(array $s): string
    {
        return "{$s['rows']} rows ({$s['vapes']} vapes), {$s['new']} new, {$s['removed']} removed. {$s['listed']} SKU(s) newly listed, {$s['delisted']} de-listed, {$s['unmatched']} THC vape(s) still unmatched.";
    }

    public function markListed(int $productId): void
    {
        $this->mountAction('listed', ['product' => $productId]);
    }

    public function linkTo(int $productId, int $entryId): void
    {
        $this->run(fn () => app(KyHempRegistry::class)->link(Product::findOrFail($productId), StateRegistryEntry::findOrFail($entryId), auth()->user()) && null, 'Linked to the KY registry');
    }

    protected function getActions(): array
    {
        return [
            Action::make('fetch')->label('Fetch now from CHFS')->icon('heroicon-o-arrow-path')->visible(static::canManage())->requiresConfirmation()
                ->modalDescription('Reads the public CHFS report ('.KyHempRegistry::URL.'), updates the registry copy and lists exact THC-vape matches.')
                ->action(fn () => $this->run(fn () => self::summary(app(KyHempRegistry::class)->import(app(KyHempRegistry::class)->fetch(), KyHempRegistry::URL, auth()->user())), 'KY registry loaded')),
            Action::make('upload')->label('Upload CSV / XLSX')->icon('heroicon-o-arrow-up-tray')->visible(static::canManage())
                ->form([Forms\Components\FileUpload::make('file')->disk('local')->directory('ky-registry')->required()->maxSize(10240)
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->helperText('Columns: Record ID, Brand of Product, Full name of the product, Type of product (others optional). Upload the FULL list: rows missing from it are marked removed.')])
                ->action(function (array $data) {
                    $this->run(function () use ($data) {
                        $path = Storage::disk('local')->path($data['file']);
                        try {
                            return self::summary(app(KyHempRegistry::class)->import(KyHempRegistry::readFile($path, $data['file']), 'upload '.basename($data['file']), auth()->user()));
                        } finally {
                            Storage::disk('local')->delete($data['file']);
                        }
                    }, 'KY registry loaded');
                }),
            Action::make('listed')->label('Mark listed / not listed')->icon('heroicon-o-check-circle')->visible(static::canManage())
                ->form([
                    Forms\Components\Select::make('product')->label('THC vape')->required()->searchable()
                        ->options(fn () => KyHempRegistry::thcVapes()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name}"])->all())
                        ->default(fn (array $arguments) => $arguments['product'] ?? null),
                    Forms\Components\Toggle::make('listed')->label('Listed on the KY registry')->default(true),
                    Forms\Components\DatePicker::make('date')->label('From (listed) / not listed from')->required()->default(now()->toDateString()),
                    Forms\Components\TextInput::make('note')->maxLength(200)->helperText('e.g. CHFS record number or approval email'),
                ])
                ->fillForm(fn (array $arguments) => ['product' => $arguments['product'] ?? null, 'listed' => true, 'date' => now()->toDateString()])
                ->action(fn (array $data) => $this->run(fn () => app(KyHempRegistry::class)->setManual(Product::findOrFail($data['product']), (bool) $data['listed'], $data['date'], auth()->user(), $data['note'] ?? null), 'Saved')),
        ];
    }
}
