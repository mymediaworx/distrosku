<?php

namespace App\Filament\RelationManagers;

use App\Filament\Resources\ActivityLogResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Read-only History tab on major records. */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'History';

    protected static ?string $icon = 'heroicon-o-clock';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->canModule('activity_log');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return ActivityLogResource::table($table)->recordTitleAttribute('event')->headerActions([])->bulkActions([]);
    }
}
