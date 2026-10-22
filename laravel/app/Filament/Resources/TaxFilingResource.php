<?php

namespace App\Filament\Resources;

use App\Exceptions\WorkflowException;
use App\Filament\Concerns\RoleGated;
use App\Filament\Pages\ReturnDrill;
use App\Filament\Resources\TaxFilingResource\Pages;
use App\Models\TaxFiling;
use App\Reports\TaxReport;
use App\Services\PostingService;
use App\Tax\ReturnLock;
use App\Tax\ReturnService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TaxFilingResource extends Resource
{
    use RoleGated;

    protected static string $module = 'tax_filings';

    protected static ?string $navigationGroup = 'Tax Filings';

    protected static ?int $navigationSort = 1;

    protected static ?string $model = TaxFiling::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function canDelete(Model $record): bool
    {
        return $record->status === 'draft' && static::allowed('manage');
    }

    public static function canCreate(): bool
    {
        return false; // created from the report pages
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('report_type')->content(fn ($record) => $record?->report_type),
            Forms\Components\Placeholder::make('period')->content(fn ($record) => $record?->period_start?->toDateString().' to '.$record?->period_end?->toDateString()),
            Forms\Components\Placeholder::make('tax_due')->content(fn ($record) => '$'.$record?->tax_due),
            Forms\Components\Placeholder::make('status')->content(fn ($record) => strtoupper($record?->status ?? '').' v'.$record?->version),
            Forms\Components\Placeholder::make('amendment_reason')->visible(fn ($record) => (bool) $record?->amendment_reason)->content(fn ($record) => $record?->amendment_reason),
            Forms\Components\TextInput::make('confirmation')->label('MyTaxes confirmation #'),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('period_start', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('report_type')->formatStateUsing(fn ($state) => TaxReport::for($state)->title())->wrap(),
                Tables\Columns\TextColumn::make('period_start')->date('M Y')->label('Period')->sortable(),
                Tables\Columns\TextColumn::make('version')->label('Ver.')->formatStateUsing(fn ($state, TaxFiling $r) => 'v'.$state.($r->superseded_at ? ' (superseded)' : ''))->tooltip(fn (TaxFiling $r) => $r->amendment_reason),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'filed' => 'success', 'approved' => 'info', default => 'warning'
                }),
                Tables\Columns\TextColumn::make('approver.name')->label('Approved by')->toggleable(),
                Tables\Columns\TextColumn::make('tax_due')->money('USD'),
                Tables\Columns\TextColumn::make('filed_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('confirmation')->toggleable(),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['draft' => 'Draft', 'approved' => 'Approved', 'filed' => 'Filed'])])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('approve')->label('Approve (locks month)')->icon('heroicon-o-lock-closed')->color('success')
                    ->visible(fn (TaxFiling $r) => $r->status === 'draft' && auth()->user()->isAdmin())
                    ->modalDescription('Regenerates the return from current data, validates the XML against the KY schema and locks the month: invoices, credit memos and stock movements dated in it can no longer change.')
                    ->requiresConfirmation()
                    ->action(fn (TaxFiling $r) => self::run(fn () => app(ReturnService::class)->approve($r, auth()->user()), 'Return approved; month locked')),
                Tables\Actions\Action::make('markFiled')->label('Mark filed')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (TaxFiling $r) => $r->status === 'approved')
                    ->form([Forms\Components\TextInput::make('confirmation')->label('MyTaxes confirmation #')->required()])
                    ->action(fn (TaxFiling $r, array $data) => self::run(fn () => app(ReturnService::class)->markFiled($r, auth()->user(), $data['confirmation']), 'Marked filed')),
                Tables\Actions\Action::make('amend')->label('Amend')->icon('heroicon-o-document-duplicate')->color('warning')
                    ->visible(fn (TaxFiling $r) => $r->isLocked() && ! $r->superseded_at && auth()->user()->isAdmin())
                    ->modalDescription('Creates the next version as a draft. The month is unlocked for corrections until the amendment is approved; its XML is marked amended (AmendedReturnIndicator X).')
                    ->form([Forms\Components\Textarea::make('reason')->label('Reason for amendment')->required()->maxLength(500)])
                    ->action(fn (TaxFiling $r, array $data) => self::run(fn () => app(ReturnService::class)->amend($r, auth()->user(), $data['reason']), 'Amended return started')),
                Tables\Actions\Action::make('refresh')->label('Regenerate')->icon('heroicon-o-arrow-path')
                    ->visible(fn (TaxFiling $r) => $r->status === 'draft')
                    ->action(fn (TaxFiling $r) => self::run(fn () => app(ReturnService::class)->refresh($r), 'Draft regenerated from current data')),
                Tables\Actions\Action::make('drill')->label('Drill-down')->icon('heroicon-o-magnifying-glass')
                    ->visible(fn (TaxFiling $r) => in_array($r->report_type, ReturnLock::TYPES, true))
                    ->url(fn (TaxFiling $r) => ReturnDrill::getUrl(['filing' => $r->id])),
                Tables\Actions\Action::make('xml')->label('XML')->icon('heroicon-o-code-bracket')
                    ->visible(fn (TaxFiling $r) => isset(ReturnService::XML[$r->report_type]))
                    ->action(fn (TaxFiling $r) => self::xml($r)),
                Tables\Actions\Action::make('pay')->label('Record excise payment')->icon('heroicon-o-banknotes')
                    ->visible(fn (TaxFiling $r) => $r->status === 'filed' && bccomp((string) $r->tax_due, (string) ($r->amount_paid ?? 0), 4) > 0)
                    ->form([
                        Forms\Components\TextInput::make('amount')->numeric()->required()->default(fn (TaxFiling $r) => bcsub((string) $r->tax_due, (string) ($r->amount_paid ?? 0), 2)),
                        Forms\Components\DatePicker::make('date')->default(now()->toDateString())->required(),
                        Forms\Components\TextInput::make('reference')->label('MyTaxes payment confirmation'),
                    ])
                    ->action(function (TaxFiling $r, array $data) {
                        app(PostingService::class)->excisePayment($r, $data['amount'], $data['date'], $data['reference'] ?? null);
                        Notification::make()->success()->title('Excise payment posted (Dr 2100 / Cr 1000)')->send();
                    }),
                Tables\Actions\Action::make('csv')->label('CSV')->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (TaxFiling $r) => response()->streamDownload(fn () => print (TaxReport::for($r->report_type)->toCsv($r->data)), "{$r->report_type}_{$r->period_start->format('Y-m')}_{$r->status}.csv")),
            ])
            ->bulkActions([]);
    }

    private static function run(\Closure $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->success()->title($ok)->send();
        } catch (WorkflowException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }
    }

    /** Approved/filed: the schema-valid snapshot taken at approval. Draft: regenerated and validated; invalid XML is blocked. */
    public static function xml(TaxFiling $r)
    {
        $name = "{$r->report_type}_{$r->period_start->format('Y-m')}_v{$r->version}".($r->version > 1 ? '_amended' : '').'.xml';
        $xml = $r->isLocked() ? $r->xml : null;
        if (! $xml) {
            [$xml, $errors] = app(ReturnService::class)->xml($r->report_type, $r->period_start->toImmutable(), $r->period_end->toImmutable(), $r->version > 1);
            if (! $xml) {
                Notification::make()->danger()->title('Download blocked: the XML does not validate against the KY schema')
                    ->body(implode("\n", array_slice($errors, 0, 12)))->persistent()->send();

                return null;
            }
        }

        return response()->streamDownload(fn () => print ($xml), $name, ['Content-Type' => 'application/xml']);
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
            'index' => Pages\ListTaxFilings::route('/'),
            'create' => Pages\CreateTaxFiling::route('/create'),
            'edit' => Pages\EditTaxFiling::route('/{record}/edit'),
        ];
    }
}
