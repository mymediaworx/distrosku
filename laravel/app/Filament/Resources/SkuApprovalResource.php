<?php

namespace App\Filament\Resources;

use App\Catalog\SkuApproval;
use App\Enums\TaxClass;
use App\Filament\Resources\SkuApprovalResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** #45: SKUs created on the Quick Tools page, waiting for an owner/admin to approve (set tax class, check price) or reject. */
class SkuApprovalResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'sku-approvals';

    protected static ?string $navigationGroup = 'Products';

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationLabel = 'New SKUs to approve';

    protected static ?string $modelLabel = 'new SKU';

    protected static ?string $pluralModelLabel = 'New SKUs to approve';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return SkuApproval::canDecide(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $n = Product::where('approval_status', SkuApproval::PENDING)->count();
        } catch (\Throwable) {
            return null; // column missing before the migration runs
        }

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('approval_status', SkuApproval::PENDING)->with(['department', 'requestedBy']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->emptyStateHeading('Nothing waiting')->emptyStateDescription('SKUs created on the Quick Tools page show up here until an owner or admin approves them.')->emptyStateIcon('heroicon-o-check-badge')
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->label('')->disk('public')->size(48),
                Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('department.name')->label('Department'),
                Tables\Columns\TextColumn::make('upc')->label('UPC')->placeholder('—'),
                Tables\Columns\TextColumn::make('price')->money('USD'),
                Tables\Columns\TextColumn::make('requestedBy.name')->label('Created by'),
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('m/d/Y g:i A')->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')->label('Approve')->icon('heroicon-o-check')->color('success')
                    ->modalDescription('Tobacco, vapor, hemp/CBD and kratom need a regulated class (not General): that keeps them from walk-in / cash customers.')
                    ->fillForm(fn (Product $r) => ['price' => $r->price, 'tax_class' => null])
                    ->form([
                        Forms\Components\Select::make('tax_class')->label('Tax class')->options(TaxClass::options())->required(),
                        Forms\Components\TextInput::make('price')->numeric()->prefix('$')->required()->minValue(0),
                        Forms\Components\TextInput::make('note')->maxLength(255),
                    ])
                    ->action(function (Product $r, array $data) {
                        try {
                            $p = app(SkuApproval::class)->approve($r, $data, auth()->user());
                            Notification::make()->success()->title("{$p->sku} approved")->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
                Tables\Actions\Action::make('reject')->label('Reject')->icon('heroicon-o-x-mark')->color('danger')
                    ->modalDescription('The SKU is archived (kept for history, never sellable).')
                    ->form([Forms\Components\TextInput::make('note')->label('Reason')->maxLength(255)])
                    ->action(function (Product $r, array $data) {
                        try {
                            $p = app(SkuApproval::class)->reject($r, $data['note'] ?? null, auth()->user());
                            Notification::make()->success()->title("{$p->sku} rejected")->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSkuApprovals::route('/')];
    }
}
