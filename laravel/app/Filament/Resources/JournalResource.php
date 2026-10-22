<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\JournalResource\Pages;
use App\Models\Journal;
use App\Services\PostingService;
use Filament\Forms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class JournalResource extends Resource
{
    use RoleGated;

    protected static string $module = 'journals';

    protected static ?string $model = Journal::class;

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    // Read-only ledger: entries come from services, never typed in.
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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('entry_date')
                    ->required(),
                TextInput::make('memo')
                    ->required(),
                TextInput::make('source_type'),
                TextInput::make('source_id')
                    ->numeric(),
                TextInput::make('total')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('entry_date')->date(),
            TextEntry::make('memo'),
            TextEntry::make('total')->money('USD'),
            RepeatableEntry::make('lines')->columnSpanFull()->columns(3)->schema([
                TextEntry::make('account.name')->formatStateUsing(fn ($state, $record) => $record->account->code.' '.$state),
                TextEntry::make('debit')->money('USD'),
                TextEntry::make('credit')->money('USD'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('entry_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('memo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('source_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('source_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->defaultSort('id', 'desc')->actions([
                Tables\Actions\Action::make('reverse')->label('Reverse')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (Journal $r) => ! $r->reversal_of_id && ! $r->reversed_at && auth()->user()->isAdmin())
                    ->form([TextInput::make('reason')->required()])
                    ->requiresConfirmation()
                    ->action(function (Journal $r, array $data) {
                        try {
                            $rev = app(PostingService::class)->reverse($r, $data['reason']);
                            Notification::make()->success()->title("Reversed by journal #{$rev->id}")->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Not reversed')->body($e->getMessage())->send();
                        }
                    }),
                Tables\Actions\ViewAction::make(),

            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournals::route('/'),
            'create' => Pages\CreateJournal::route('/create'),
            'edit' => Pages\EditJournal::route('/{record}/edit'),
        ];
    }
}
