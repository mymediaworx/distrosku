<?php

namespace App\Filament\Resources;

use App\Compliance\PactReportService;
use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\PactReportResource\Pages;
use App\Models\PactReport;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Monthly PACT Act reports per state (15 U.S.C. 376(a)(2)), due the 10th. Generate, print / CSV, mark filed (locks). */
class PactReportResource extends Resource
{
    use RoleGated;

    protected static string $module = 'pact';

    protected static ?string $model = PactReport::class;

    protected static ?string $navigationGroup = 'Tax Reports';

    protected static ?string $navigationLabel = 'PACT Act reports';

    protected static ?string $navigationIcon = 'heroicon-o-truck';

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

    public static function generateAction(string $ns)
    {
        return $ns::make('generate')->label('Generate report')->icon('heroicon-o-document-plus')
            ->form([
                Forms\Components\TextInput::make('period')->label('Month (YYYY-MM)')->default(now()->subMonth()->format('Y-m'))->required()->regex('/^\d{4}-\d{2}$/'),
                Forms\Components\TextInput::make('state')->label('State (blank = every state with shipments)')->maxLength(2),
            ])
            ->action(function (array $data) {
                $svc = app(PactReportService::class);
                try {
                    $states = $data['state'] ? [strtoupper($data['state'])] : $svc->statesWithShipments($data['period']);
                    foreach ($states as $s) {
                        $svc->generate($s, $data['period']);
                    }
                    Notification::make()->success()->title($states ? 'Generated: '.implode(', ', $states) : 'No interstate covered shipments that month')->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('period', 'desc')->modifyQueryUsing(fn ($query) => $query->withCount('lines'))->columns([
            Tables\Columns\TextColumn::make('period')->sortable(),
            Tables\Columns\TextColumn::make('state')->badge()->searchable(),
            Tables\Columns\TextColumn::make('lines_count')->label('Lines'),
            Tables\Columns\TextColumn::make('due_on')->date()->color(fn (PactReport $r) => $r->status !== 'filed' && $r->due_on->isPast() ? 'danger' : null),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => $state === 'filed' ? 'success' : 'warning'),
            Tables\Columns\TextColumn::make('filed_reference')->label('Filing ref'),
        ])->actions([
            Tables\Actions\Action::make('view')->icon('heroicon-o-eye')->url(fn (PactReport $r) => route('pact.report', $r), shouldOpenInNewTab: true),
            Tables\Actions\Action::make('csv')->label('CSV')->icon('heroicon-o-arrow-down-tray')->url(fn (PactReport $r) => route('pact.report', ['report' => $r, 'format' => 'csv'])),
            Tables\Actions\Action::make('regen')->label('Regenerate')->icon('heroicon-o-arrow-path')->visible(fn (PactReport $r) => $r->status === 'draft')
                ->action(fn (PactReport $r) => app(PactReportService::class)->generate($r->state, $r->period)),
            Tables\Actions\Action::make('filed')->label('Mark filed')->icon('heroicon-o-lock-closed')->visible(fn (PactReport $r) => $r->status === 'draft' && auth()->user()?->isAdmin())
                ->form([Forms\Components\TextInput::make('reference')->label('Confirmation / reference')])
                ->action(function (PactReport $r, array $data) {
                    app(PactReportService::class)->markFiled($r, auth()->user(), $data['reference'] ?? null);
                    Notification::make()->success()->title('Marked filed; report locked')->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPactReports::route('/')];
    }
}
