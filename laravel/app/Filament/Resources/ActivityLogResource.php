<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
use App\Models\User;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/** Phase 16: read-only Activity log. No create / edit / delete anywhere in the UI. */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $modelLabel = 'activity entry';
    protected static ?string $pluralModelLabel = 'activity log'; // page title matches the sidebar (UI audit #6)

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Activity log';

    protected static ?string $slug = 'activity-log';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->canModule('activity_log');
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function diffHtml(?array $old, ?array $new): HtmlString
    {
        $keys = array_unique(array_merge(array_keys($old ?? []), array_keys($new ?? [])));
        if (! $keys) {
            return new HtmlString('<span style="color:#6b7280">No field values</span>');
        }
        $f = fn ($v) => e(is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) ($v ?? '∅')));
        $rows = '';
        foreach ($keys as $k) {
            $rows .= '<tr><td style="padding:2px 8px;font-weight:600">'.e($k).'</td><td style="padding:2px 8px;color:#b91c1c">'.(array_key_exists($k, $old ?? []) ? $f($old[$k]) : '').'</td><td style="padding:2px 8px;color:#047857">'.(array_key_exists($k, $new ?? []) ? $f($new[$k]) : '').'</td></tr>';
        }

        return new HtmlString('<table data-test="diff" style="font-size:.8rem;border-collapse:collapse"><tr><th style="text-align:left;padding:2px 8px">Field</th><th style="text-align:left;padding:2px 8px">Old</th><th style="text-align:left;padding:2px 8px">New</th></tr>'.$rows.'</table>');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('created_at')->label('When')->dateTime('M j, Y g:i:s A T'),
            Infolists\Components\TextEntry::make('event'),
            Infolists\Components\TextEntry::make('user_name')->label('User')->placeholder('system'),
            Infolists\Components\TextEntry::make('ip')->label('IP')->placeholder('-'),
            Infolists\Components\TextEntry::make('subject_type')->label('Record type')->formatStateUsing(fn ($state) => class_basename((string) $state))->placeholder('-'),
            Infolists\Components\TextEntry::make('subject_label')->label('Record')->formatStateUsing(fn ($state, ActivityLog $r) => "{$state} (#{$r->subject_id})")->placeholder('-'),
            Infolists\Components\TextEntry::make('description')->columnSpanFull()->placeholder('-'),
            Infolists\Components\TextEntry::make('user_agent')->columnSpanFull()->placeholder('-'),
            Infolists\Components\TextEntry::make('changes')->columnSpanFull()->state(fn (ActivityLog $r) => static::diffHtml($r->old_values, $r->new_values)),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('M j, Y g:i A')->sortable(),
                Tables\Columns\TextColumn::make('event')->badge()->searchable(),
                Tables\Columns\TextColumn::make('user_name')->label('User')->placeholder('system')->searchable(),
                Tables\Columns\TextColumn::make('subject_type')->label('Type')->formatStateUsing(fn ($state) => class_basename((string) $state)),
                Tables\Columns\TextColumn::make('subject_label')->label('Record')->searchable()->description(fn (ActivityLog $r) => $r->subject_id ? '#'.$r->subject_id : null),
                Tables\Columns\TextColumn::make('description')->limit(60)->toggleable(),
                Tables\Columns\TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')->label('User')->searchable()
                    ->options(fn () => User::orderBy('name')->limit(200)->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('subject_type')->label('Record type')
                    ->options(fn () => ActivityLog::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')
                        ->mapWithKeys(fn ($t) => [$t => class_basename($t)])->all()),
                Tables\Filters\Filter::make('record')->form([Forms\Components\TextInput::make('subject_id')->label('Record ID')])
                    ->query(fn (Builder $q, array $data) => $q->when($data['subject_id'] ?? null, fn ($q, $id) => $q->where('subject_id', (string) $id))),
                Tables\Filters\SelectFilter::make('event')->label('Action')->multiple()
                    ->options(fn () => ActivityLog::query()->distinct()->orderBy('event')->pluck('event', 'event')->all()),
                Tables\Filters\Filter::make('date')->form([
                    Forms\Components\DatePicker::make('from'), Forms\Components\DatePicker::make('until'),
                ])->query(fn (Builder $q, array $data) => $q
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, config('app.timezone'))->startOfDay()))
                    ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d, config('app.timezone'))->endOfDay()))),
            ])
            ->actions([Tables\Actions\ViewAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }
}
