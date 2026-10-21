<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\InventoryCountResource\Pages;
use App\Filament\Resources\InventoryCountResource\RelationManagers\LinesRelationManager;
use App\Inventory\CountService;
use App\Models\Bin;
use App\Models\InventoryCount;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Cycle / full counts. Starting freezes the items; approval (admin) posts the variance to stock and the GL. */
class InventoryCountResource extends Resource
{
    use RoleGated;

    protected static string $module = 'counts';

    protected static ?string $model = InventoryCount::class;

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Counts';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 70;

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('warehouse_id')->relationship('warehouse', 'name')->required()->default(1)->live(),
            Forms\Components\Select::make('type')->options(['cycle' => 'Cycle count (selected bins / items)', 'full' => 'Full count (whole warehouse)'])->default('cycle')->required()->live(),
            Forms\Components\Toggle::make('blind')->label('Blind count (counters do not see expected qty)')->default(true),
            Forms\Components\Select::make('bin_ids')->label('Bins')->multiple()->searchable()->visible(fn (Forms\Get $get) => $get('type') === 'cycle')
                ->options(fn (Forms\Get $get) => Bin::where('warehouse_id', $get('warehouse_id'))->where('active', true)->orderBy('code')->pluck('code', 'id')),
            Forms\Components\Select::make('product_ids')->label('Items')->multiple()->searchable()->visible(fn (Forms\Get $get) => $get('type') === 'cycle')
                ->getSearchResultsUsing(fn (string $search) => Product::where('sku', 'like', "%$search%")->orWhere('name', 'like', "%$search%")->limit(50)
                    ->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name}"]))
                ->getOptionLabelsUsing(fn (array $values) => Product::whereIn('id', $values)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} {$p->name}"])),
            Forms\Components\TextInput::make('notes')->maxLength(255)->columnSpanFull(),
        ])->columns(2);
    }

    private static function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->success()->title($ok)->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    /** Lifecycle actions, shared by the table and the view page. */
    public static function lifecycleActions(string $ns): array
    {
        $svc = fn () => app(CountService::class);

        return [
            $ns::make('start')->label('Start (freeze)')->icon('heroicon-o-lock-closed')->visible(fn (InventoryCount $r) => $r->status === 'draft')
                ->requiresConfirmation()->modalDescription('Snapshots expected stock and freezes the items until the count is posted or cancelled.')
                ->action(fn (InventoryCount $r) => self::run(fn () => $svc()->start($r), "Count {$r->number} started; items frozen")),
            $ns::make('scan')->label('Scanner')->icon('heroicon-o-qr-code')->visible(fn (InventoryCount $r) => $r->status === 'counting')
                ->url(fn (InventoryCount $r) => route('wh.count', $r)),
            $ns::make('sheet')->label('Count sheet')->icon('heroicon-o-printer')->visible(fn (InventoryCount $r) => $r->status !== 'draft')
                ->url(fn (InventoryCount $r) => route('wh.print.count', $r), shouldOpenInNewTab: true),
            $ns::make('submit')->icon('heroicon-o-paper-airplane')->visible(fn (InventoryCount $r) => $r->status === 'counting')
                ->form([Forms\Components\Toggle::make('zero')->label('Treat uncounted lines as zero')])
                ->action(fn (InventoryCount $r, array $data) => self::run(fn () => $svc()->submit($r, (bool) ($data['zero'] ?? false), auth()->user()), 'Submitted for approval')),
            $ns::make('approve')->label('Approve & post')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (InventoryCount $r) => $r->status === 'submitted' && auth()->user()?->isAdmin())
                ->requiresConfirmation()->modalDescription(fn (InventoryCount $r) => 'Net variance value $'.number_format((float) $r->variance_value, 2)
                    .'. Stock and lots are adjusted and a journal is posted (Inventory vs 5200 Shrinkage). The items are unfrozen.')
                ->action(fn (InventoryCount $r) => self::run(fn () => $svc()->approve($r, auth()->user()), 'Count posted')),
            $ns::make('reopen')->label('Send back for recount')->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (InventoryCount $r) => $r->status === 'submitted' && auth()->user()?->isAdmin())->requiresConfirmation()
                ->action(fn (InventoryCount $r) => self::run(fn () => $svc()->reopen($r, auth()->user()), 'Reopened for counting')),
            $ns::make('cancel')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (InventoryCount $r) => in_array($r->status, ['draft', 'counting', 'submitted'], true) && ($r->status === 'draft' || auth()->user()?->isAdmin()))
                ->requiresConfirmation()->modalDescription('No stock is changed; the items are unfrozen.')
                ->action(fn (InventoryCount $r) => self::run(fn () => $svc()->cancel($r, auth()->user()), 'Count cancelled')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('warehouse')->withCount(['lines', 'lines as counted_count' => fn ($q) => $q->whereNotNull('counted')]))
            ->columns([
                Tables\Columns\TextColumn::make('number')->searchable(),
                Tables\Columns\TextColumn::make('warehouse.code')->label('WH'),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\IconColumn::make('blind')->boolean(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'counting' => 'warning', 'submitted' => 'info', 'posted' => 'success', 'cancelled' => 'gray', default => 'gray'
                }),
                Tables\Columns\IconColumn::make('auto_approved')->label('Auto')->boolean()->tooltip('Posted automatically: every line within the Settings > Warehouse threshold'),
                Tables\Columns\TextColumn::make('progress')->state(fn (InventoryCount $r) => "{$r->counted_count}/{$r->lines_count}"),
                Tables\Columns\TextColumn::make('variance_value')->label('Variance $')->money('USD')
                    ->visible(fn () => auth()->user()?->isAdmin()),
                Tables\Columns\TextColumn::make('created_at')->date(),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['draft' => 'Draft', 'counting' => 'Counting', 'submitted' => 'Submitted', 'posted' => 'Posted', 'cancelled' => 'Cancelled'])])
            ->actions([Tables\Actions\ViewAction::make(), Tables\Actions\ActionGroup::make(self::lifecycleActions(Tables\Actions\Action::class))]);
    }

    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
            \App\Filament\RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryCounts::route('/'),
            'create' => Pages\CreateInventoryCount::route('/create'),
            'view' => Pages\ViewInventoryCount::route('/{record}'),
        ];
    }
}
