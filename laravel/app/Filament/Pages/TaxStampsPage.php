<?php

namespace App\Filament\Pages;

use App\Compliance\TaxStamps;
use App\Models\Product;
use App\Models\TaxStampAffixing;
use App\Models\TaxStampPurchase;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** #40: Compliance > Tax stamps (only when stamping is on): purchases, affixing, reconciliation. */
class TaxStampsPage extends Page
{
    protected static string $view = 'filament.pages.tax-stamps';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Tax stamps';

    protected static ?string $title = 'Tax stamps';

    protected static ?string $slug = 'compliance/tax-stamps';

    public string $from = '';

    public string $to = '';

    public static function canAccess(): bool
    {
        return TaxStamps::enabled() && (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    protected function getHeaderActions(): array
    {
        $run = function (callable $fn, string $ok) {
            try {
                $fn();
                Notification::make()->success()->title($ok)->send();
            } catch (\Throwable $e) {
                Notification::make()->danger()->title($e->getMessage())->send();
            }
        };

        return [
            Action::make('buy')->label('Record stamp purchase')->icon('heroicon-o-shopping-bag')
                ->form([
                    Forms\Components\TextInput::make('state')->default('KY')->maxLength(2)->required(),
                    Forms\Components\Select::make('pack_size')->options([20 => 'Packs of 20', 25 => 'Packs of 25'])->default(20)->required(),
                    Forms\Components\TextInput::make('stamps')->label('Number of stamps')->integer()->minValue(1)->required(),
                    Forms\Components\TextInput::make('unit_cost')->label('Cost per stamp')->numeric()->prefix('$')->default(0),
                    Forms\Components\DatePicker::make('purchased_on')->default(now())->required(),
                    Forms\Components\TextInput::make('reference')->maxLength(80),
                ])
                ->action(fn (array $data) => $run(fn () => app(TaxStamps::class)->buy($data, auth()->user()), 'Stamp purchase recorded')),
            Action::make('affix')->label('Record stamping')->icon('heroicon-o-check-badge')
                ->form([
                    Forms\Components\Select::make('from_product_id')->label('Unstamped SKU')->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => Product::whereNull('stamp_state')->where(fn ($q) => $q->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(20)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name}"])),
                    Forms\Components\Select::make('to_product_id')->label('Stamped SKU it becomes')->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => Product::whereNotNull('stamp_state')->where(fn ($q) => $q->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(20)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name} ({$p->stamp_state})"])),
                    Forms\Components\Select::make('warehouse_id')->options(Warehouse::where('active', true)->pluck('name', 'id'))->default(Warehouse::where('code', 'MAIN')->value('id'))->required(),
                    Forms\Components\Select::make('pack_size')->options([20 => 'Packs of 20', 25 => 'Packs of 25'])->default(20)->required(),
                    Forms\Components\TextInput::make('packs')->label('Packs stamped')->integer()->minValue(1)->required(),
                    Forms\Components\DatePicker::make('affixed_on')->default(now())->required(),
                    Forms\Components\TextInput::make('notes')->maxLength(255),
                ])
                ->action(fn (array $data) => $run(fn () => app(TaxStamps::class)->affix($data, auth()->user()), 'Stamping recorded')),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'rows' => TaxStamps::reconciliation($this->from ?: now()->startOfMonth()->toDateString(), $this->to ?: now()->toDateString()),
            'purchases' => TaxStampPurchase::latest('purchased_on')->latest('id')->limit(15)->get(),
            'affixings' => TaxStampAffixing::with('fromProduct', 'toProduct', 'warehouse', 'user')->latest('affixed_on')->latest('id')->limit(15)->get(),
        ];
    }
}
