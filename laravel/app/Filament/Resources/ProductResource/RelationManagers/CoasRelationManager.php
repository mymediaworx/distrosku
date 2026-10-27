<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\ProductCoa;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** 0.18: certificates of analysis (private disk; download through the app only). */
class CoasRelationManager extends RelationManager
{
    protected static string $relationship = 'coas';

    protected static ?string $title = 'COAs';

    protected static ?string $icon = 'heroicon-o-beaker';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->canModule('products');
    }

    public function isReadOnly(): bool
    {
        return ! auth()->user()?->canModule('products', 'manage');
    }

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\FileUpload::make('file_path')->label('COA file (PDF or image)')->disk('local')->directory('coas')->visibility('private')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])->maxSize(10240)
                ->storeFileNamesIn('original_name')->downloadable(false)->columnSpanFull(),
            Forms\Components\TextInput::make('lab')->maxLength(190),
            Forms\Components\TextInput::make('batch_lot')->label('Batch / lot')->maxLength(100),
            Forms\Components\Select::make('lot_id')->label('Linked lot (0.20.15)')->searchable()->nullable()
                ->options(fn () => \App\Models\Lot::where('product_id', $this->getOwnerRecord()->id)->orderByDesc('id')->limit(300)->pluck('lot_number', 'id')->all())
                ->helperText('Links this COA to a received lot, so the lot no longer shows "COA missing".'),
            Forms\Components\DatePicker::make('tested_on')->label('Test date'),
            Forms\Components\DatePicker::make('expires_on')->label('Expires')->afterOrEqual('tested_on'),
            Forms\Components\TextInput::make('thc_total_mg')->label('Total THC on COA (mg/container)')->numeric()->minValue(0),
            Forms\Components\TextInput::make('notes')->maxLength(500),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('batch_lot')
            ->columns([
                Tables\Columns\TextColumn::make('lab'),
                Tables\Columns\TextColumn::make('batch_lot')->label('Batch / lot')->searchable(),
                Tables\Columns\TextColumn::make('lot.lot_number')->label('Lot')->placeholder('not linked'),
                Tables\Columns\TextColumn::make('tested_on')->label('Tested')->date(),
                Tables\Columns\TextColumn::make('expires_on')->label('Expires')->date(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (ProductCoa $r) => $r->state())
                    ->formatStateUsing(fn (string $state) => ['current' => 'Current', 'expiring' => 'Expiring soon', 'expired' => 'Expired', 'no_expiry' => 'No expiry date'][$state])
                    ->color(fn (string $state) => ['current' => 'success', 'expiring' => 'warning', 'expired' => 'danger', 'no_expiry' => 'gray'][$state]),
                Tables\Columns\TextColumn::make('thc_total_mg')->label('THC mg'),
                Tables\Columns\TextColumn::make('original_name')->label('File')->limit(30),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Upload COA')
                    ->mutateFormDataUsing(fn (array $data) => $data + ['uploaded_by' => auth()->id()]),
            ])
            ->actions([
                Tables\Actions\Action::make('download')->label('Download')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (ProductCoa $r) => $r->file_path && Storage::disk('local')->exists($r->file_path))
                    ->action(fn (ProductCoa $r) => Storage::disk('local')->download($r->file_path, $r->original_name ?: basename($r->file_path))),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }
}
