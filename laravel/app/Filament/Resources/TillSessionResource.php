<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\TillSessionResource\Pages;
use App\Models\TillSession;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** #44: every till shift with its count and over/short, linked to the Z-report. */
class TillSessionResource extends Resource
{
    use RoleGated;

    protected static string $module = 'payments';

    protected static ?string $model = TillSession::class;

    protected static ?string $navigationGroup = 'Payments';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Till sessions';

    protected static ?string $pluralModelLabel = 'Till sessions';

    protected static ?int $navigationSort = 20;

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

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')
            ->emptyStateHeading('No till sessions yet')->emptyStateDescription('Open the till from the counter POS (Till link above the total) at the start of a shift.')
            ->columns([
                Tables\Columns\TextColumn::make('register')->searchable(),
                Tables\Columns\TextColumn::make('opened_at')->label('Opened')->dateTime('m/d/Y g:i A')->sortable(),
                Tables\Columns\TextColumn::make('openedBy.name')->label('By'),
                Tables\Columns\TextColumn::make('closed_at')->label('Closed')->dateTime('m/d/Y g:i A')->placeholder('Open'),
                Tables\Columns\TextColumn::make('expected_cash')->label('Expected')->money('USD')->placeholder('—'),
                Tables\Columns\TextColumn::make('counted_cash')->label('Counted')->money('USD')->placeholder('—'),
                Tables\Columns\TextColumn::make('variance')->label('Over / short')->money('USD')->placeholder('—')
                    ->color(fn ($state) => $state === null ? null : ((float) $state < 0 ? 'danger' : ((float) $state > 0 ? 'warning' : 'success'))),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => $state === 'open' ? 'warning' : 'gray'),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['open' => 'Open', 'closed' => 'Closed'])])
            ->actions([
                Tables\Actions\Action::make('z')->label(fn (TillSession $r) => $r->isOpen() ? 'X-report' : 'Z-report')->icon('heroicon-o-printer')
                    ->url(fn (TillSession $r) => route('pos.till.z', $r))->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTillSessions::route('/')];
    }
}
