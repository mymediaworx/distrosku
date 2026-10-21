<?php

namespace App\Filament\Resources;

use App\Compliance\MsaDirectory;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\MsaDirectoryImportResource\Pages;
use App\Filament\Resources\MsaDirectoryImportResource\RelationManagers\EntriesRelationManager;
use App\Models\MsaDirectoryImport;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** State MSA directory lists (CSV per state with effective date). Enforced by ComplianceChecker for cigarettes + RYO. */
class MsaDirectoryImportResource extends Resource
{
    use RoleGated;

    protected static string $module = 'msa_directory';

    protected static ?string $model = MsaDirectoryImport::class;

    protected static ?string $navigationGroup = 'Tax Reports';

    protected static ?string $navigationLabel = 'MSA state directories';

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

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

    public static function importAction(string $ns)
    {
        return $ns::make('import')->label('Import state list (CSV)')->icon('heroicon-o-arrow-up-tray')
            ->visible(fn () => auth()->user()?->canModule('msa_directory', 'manage'))
            ->form([
                Forms\Components\TextInput::make('state')->required()->maxLength(2)->regex('/^[A-Za-z]{2}$/'),
                Forms\Components\DatePicker::make('effective_on')->required()->default(now()),
                Forms\Components\TextInput::make('source')->label('Source (URL / publication date)')->maxLength(250),
                Forms\Components\FileUpload::make('file')->label('CSV: manufacturer, brand_family, product_type [, effective_from, effective_to]')
                    ->disk('local')->directory('msa-directory')->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])->maxSize(5120)->required()->storeFileNamesIn('filename'),
            ])
            ->action(function (array $data) {
                try {
                    $imp = app(MsaDirectory::class)->import($data['state'], Storage::disk('local')->get($data['file']), $data['effective_on'], auth()->user(),
                        $data['filename'] ?? basename($data['file']), $data['source'] ?? null);
                    Notification::make()->success()->title("{$imp->state} directory loaded: {$imp->rows} brand entries from {$imp->effective_on->toDateString()}")->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                } finally {
                    Storage::disk('local')->delete($data['file']);
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->modifyQueryUsing(fn ($query) => $query->with('user'))->columns([
            Tables\Columns\TextColumn::make('state')->badge()->searchable(),
            Tables\Columns\TextColumn::make('effective_on')->date()->sortable(),
            Tables\Columns\TextColumn::make('rows')->label('Entries'),
            Tables\Columns\TextColumn::make('filename')->limit(30),
            Tables\Columns\TextColumn::make('source')->limit(30),
            Tables\Columns\TextColumn::make('user.name')->label('By'),
            Tables\Columns\TextColumn::make('created_at')->dateTime(),
        ])->actions([Tables\Actions\ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [EntriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMsaDirectoryImports::route('/'), 'view' => Pages\ViewMsaDirectoryImport::route('/{record}')];
    }
}
