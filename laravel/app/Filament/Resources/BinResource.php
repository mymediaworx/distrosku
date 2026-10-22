<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\BinResource\Pages;
use App\Models\Bin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** Warehouse bin locations. The bin code is also its barcode label; pick sequence sets the pick-list walk order. */
class BinResource extends Resource
{
    use RoleGated;

    protected static string $module = 'bins';

    protected static ?string $model = Bin::class;

    protected static ?string $navigationGroup = 'Warehouse';

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 3;

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('warehouse_id')->relationship('warehouse', 'name')->required()->default(1),
            Forms\Components\TextInput::make('code')->required()->maxLength(30)->regex('/^[A-Za-z0-9._-]+$/')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Forms\Get $get) => $rule->where('warehouse_id', $get('warehouse_id'))),
            Forms\Components\TextInput::make('zone')->maxLength(30),
            Forms\Components\TextInput::make('pick_sequence')->integer()->default(0),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('pick_sequence')->columns([
            Tables\Columns\TextColumn::make('warehouse.code')->label('WH'),
            Tables\Columns\TextColumn::make('code')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('zone'),
            Tables\Columns\TextColumn::make('pick_sequence')->sortable(),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->actions([
            Tables\Actions\Action::make('label')->icon('heroicon-o-qr-code')->url(fn (Bin $r) => route('wh.print.bins', ['ids' => $r->id]), shouldOpenInNewTab: true),
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkAction::make('labels')->label('Print labels')->icon('heroicon-o-qr-code')
                ->action(fn ($records, $livewire) => $livewire->js('window.open('.json_encode(route('wh.print.bins', ['ids' => $records->pluck('id')->implode(',')])).', "_blank")'))
                ->deselectRecordsAfterCompletion(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBins::route('/'),
            'create' => Pages\CreateBin::route('/create'),
            'edit' => Pages\EditBin::route('/{record}/edit'),
        ];
    }
}
