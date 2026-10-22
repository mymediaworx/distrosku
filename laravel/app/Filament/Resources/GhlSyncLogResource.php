<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GhlSyncLogResource\Pages;
use App\Marketing\Jobs\MarketingJob;
use App\Marketing\MarketingSettings;
use App\Models\GhlSyncLog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** 0.19: BrandWorx Marketing sync log (owner/admin; only when the module is on). */
class GhlSyncLogResource extends Resource
{
    protected static ?string $model = GhlSyncLog::class;

    protected static ?string $pluralModelLabel = 'marketing sync log'; // page title matches the sidebar (UI audit #6)

    protected static ?string $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Marketing sync log';

    protected static ?string $modelLabel = 'sync entry';

    protected static ?string $slug = 'marketing-sync-log';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    public static function canViewAny(): bool
    {
        return MarketingSettings::enabled() && (bool) auth()->user()?->canModule('marketing', 'manage');
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

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->poll('15s')->columns([
            Tables\Columns\TextColumn::make('created_at')->dateTime()->label('When'),
            Tables\Columns\TextColumn::make('kind')->badge(),
            Tables\Columns\TextColumn::make('event'),
            Tables\Columns\TextColumn::make('subject')->state(fn (GhlSyncLog $r) => $r->subject_type ? class_basename($r->subject_type).' #'.$r->subject_id : '-'),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) { 'ok' => 'success', 'failed' => 'danger', 'skipped' => 'gray', default => 'warning' }),
            Tables\Columns\TextColumn::make('attempts'),
            Tables\Columns\TextColumn::make('http_status')->label('HTTP'),
            Tables\Columns\TextColumn::make('summary')->limit(50)->wrap(),
            Tables\Columns\TextColumn::make('error')->limit(80)->wrap(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(['queued' => 'Queued', 'ok' => 'OK', 'failed' => 'Failed', 'skipped' => 'Skipped']),
            Tables\Filters\SelectFilter::make('kind')->options(['contact' => 'Contact', 'event' => 'Event', 'notify' => 'Notification']),
        ])->actions([
            Tables\Actions\Action::make('retry')->icon('heroicon-o-arrow-path')->visible(fn (GhlSyncLog $r) => $r->status === 'failed' && $r->kind === 'contact')
                ->action(function (GhlSyncLog $r) {
                    $r->update(['status' => 'queued']);
                    MarketingJob::dispatch('contact', $r->id, ['customer_id' => $r->subject_id]);
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListGhlSyncLogs::route('/')];
    }
}
