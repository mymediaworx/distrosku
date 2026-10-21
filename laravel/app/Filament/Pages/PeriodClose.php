<?php

namespace App\Filament\Pages;

use App\Accounting\PeriodService;
use App\Models\FiscalPeriod;
use App\Models\PeriodAudit;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Throwable;

/** Monthly close: closed months refuse every posting (invoices, payments, receipts, bills, adjustments). Admin reopen needs a reason; all audited. */
class PeriodClose extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.period-close';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $title = 'Period close';

    protected static ?string $slug = 'accounting/periods';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && $u->canModule('periods');
    }

    public function mount(): void
    {
        $m = CarbonImmutable::now()->startOfMonth();
        for ($i = 0; $i < 18; $i++) {
            PeriodService::ensure($m->subMonthsNoOverflow($i)->format('Y-m'));
        }
    }

    public function audits()
    {
        return PeriodAudit::with('period', 'user')->latest()->limit(30)->get();
    }

    public function table(Table $table): Table
    {
        $checklist = fn (FiscalPeriod $p) => collect(app(PeriodService::class)->checklist($p->period))
            ->map(fn ($n, $k) => "$k: $n")->implode(' · ');

        return $table->query(FiscalPeriod::query())->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period')->sortable(),
                Tables\Columns\TextColumn::make('starts_on')->date(),
                Tables\Columns\TextColumn::make('ends_on')->date(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => $state === 'closed' ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('closed_at')->dateTime(),
                Tables\Columns\TextColumn::make('closedBy.name')->label('Closed by'),
            ])
            ->actions([
                Tables\Actions\Action::make('close')->label('Close')->icon('heroicon-o-lock-closed')->color('danger')
                    ->visible(fn (FiscalPeriod $p) => $p->status === 'open' && auth()->user()->isAdmin())
                    ->modalDescription(fn (FiscalPeriod $p) => "Before closing {$p->period}: ".$checklist($p).'. After closing, nothing can post with a date in this month until an admin reopens it.')
                    ->form([Forms\Components\TextInput::make('reason')->label('Note (optional)')])
                    ->action(fn (FiscalPeriod $p, array $data) => $this->act(fn () => app(PeriodService::class)->close($p->period, auth()->user(), $data['reason'] ?? null), "{$p->period} closed")),
                Tables\Actions\Action::make('reopen')->label('Reopen')->icon('heroicon-o-lock-open')->color('warning')
                    ->visible(fn (FiscalPeriod $p) => $p->status === 'closed' && auth()->user()->isAdmin())
                    ->form([Forms\Components\Textarea::make('reason')->label('Reason (recorded in the audit log)')->required()])
                    ->action(fn (FiscalPeriod $p, array $data) => $this->act(fn () => app(PeriodService::class)->reopen($p->period, auth()->user(), $data['reason']), "{$p->period} reopened")),
            ]);
    }

    private function act(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->success()->title($ok)->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();
        }
    }
}
