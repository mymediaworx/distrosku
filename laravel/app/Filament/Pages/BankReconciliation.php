<?php

namespace App\Filament\Pages;

use App\Accounting\BankReconService;
use App\Analytics\ReportService;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Services\Money;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Import a bank CSV statement, auto/manual match to GL, post bank-only adjustments, reconcile, export the report. Admin-level ('bank'). */
class BankReconciliation extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.bank-reconciliation';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $title = 'Bank reconciliation';

    protected static ?string $slug = 'accounting/bank-reconciliation';

    protected static ?int $navigationSort = 16;

    public $bank = null;

    public $statement = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && $u->canModule('bank');
    }

    public function mount(): void
    {
        $this->bank = request()->query('bank', BankAccount::default()?->id);
        $this->statement = request()->query('statement', BankStatement::where('bank_account_id', $this->bank)->latest('statement_date')->value('id'));
    }

    public function updatedBank(): void
    {
        $this->statement = BankStatement::where('bank_account_id', $this->bank)->latest('statement_date')->value('id');
        $this->resetTable();
    }

    public function updatedStatement(): void
    {
        $this->resetTable();
    }

    public function bankOptions(): array
    {
        return BankAccount::orderBy('name')->pluck('name', 'id')->all();
    }

    public function statementOptions(): array
    {
        return BankStatement::where('bank_account_id', $this->bank ?: 0)->orderByDesc('statement_date')->get()
            ->mapWithKeys(fn ($s) => [$s->id => $s->statement_date->toDateString().' · '.$s->status.' · closing '.ReportService::d2($s->closing_balance)])->all();
    }

    public function current(): ?BankStatement
    {
        return $this->statement ? BankStatement::with('bankAccount.account')->find($this->statement) : null;
    }

    public function summary(): ?array
    {
        $s = $this->current();

        return $s ? app(BankReconService::class)->summary($s) : null;
    }

    private function svc(): BankReconService
    {
        return app(BankReconService::class);
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            Notification::make()->success()->title($ok)->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $open = fn () => ($s = $this->current()) && $s->status === 'open';
        $name = fn ($ext) => 'bank-rec-'.($this->current()?->statement_date?->format('Ymd') ?? 'x').'.'.$ext;

        return [
            Action::make('import')->label('Import statement (CSV)')->icon('heroicon-o-arrow-up-tray')
                ->form([
                    Forms\Components\Select::make('bank_account_id')->label('Bank account')->options($this->bankOptions())->default($this->bank)->required(),
                    Forms\Components\DatePicker::make('statement_date')->required(),
                    Forms\Components\TextInput::make('opening_balance')->numeric()->required()
                        ->default(fn () => BankStatement::where('bank_account_id', $this->bank)->latest('statement_date')->value('closing_balance')),
                    Forms\Components\TextInput::make('closing_balance')->numeric()->required(),
                    Forms\Components\FileUpload::make('file')->label('CSV file')->disk('local')->directory('bank-statements')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])->maxSize(4096),
                    Forms\Components\Textarea::make('paste')->label('…or paste CSV text')->rows(4)
                        ->helperText('Header row needed: Date, Description, Amount (or Debit + Credit / Withdrawal + Deposit), optional Reference / Check #.'),
                ])
                ->action(function (array $data) {
                    $this->run(function () use ($data) {
                        $csv = trim((string) ($data['paste'] ?? ''));
                        $fname = null;
                        if (! empty($data['file'])) {
                            $csv = Storage::disk('local')->get($data['file']);
                            $fname = basename($data['file']);
                        }
                        $s = $this->svc()->import(BankAccount::findOrFail($data['bank_account_id']), (string) $csv, $data['statement_date'],
                            $data['opening_balance'], $data['closing_balance'], $fname);
                        $this->bank = $s->bank_account_id;
                        $this->statement = $s->id;
                        $this->resetTable();
                    }, 'Statement imported and auto-matched');
                }),
            Action::make('auto')->label('Auto-match')->icon('heroicon-o-sparkles')->visible($open)
                ->action(fn () => $this->run(fn () => $this->svc()->autoMatch($this->current()), 'Auto-match done')),
            Action::make('reconcile')->label('Finish reconciliation')->icon('heroicon-o-check-badge')->color('success')->visible($open)->requiresConfirmation()
                ->action(fn () => $this->run(fn () => $this->svc()->reconcile($this->current(), auth()->user()), 'Statement reconciled')),
            Action::make('csv')->label('CSV')->icon('heroicon-o-arrow-down-tray')->visible(fn () => (bool) $this->current())
                ->action(fn () => response()->streamDownload(fn () => print (ReportService::toCsv($this->svc()->report($this->current()))), $name('csv'), ['Content-Type' => 'text/csv'])),
            Action::make('pdf')->label('PDF')->icon('heroicon-o-document-arrow-down')->visible(fn () => (bool) $this->current())
                ->action(fn () => response()->streamDownload(fn () => print (ReportService::toPdf($this->svc()->report($this->current()))), $name('pdf'), ['Content-Type' => 'application/pdf'])),
        ];
    }

    public function table(Table $table): Table
    {
        $editable = fn () => $this->current()?->status === 'open';

        return $table->query(BankStatementLine::query()->where('bank_statement_id', $this->statement ?: 0)->with('journalLine.journal'))
            ->defaultSort('txn_date')->paginated([25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('txn_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('description')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('reference'),
                Tables\Columns\TextColumn::make('amount')->money('USD')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) { 'matched' => 'success', 'adjusted' => 'info', default => 'warning' }),
                Tables\Columns\TextColumn::make('journalLine.journal.memo')->label('Matched GL entry')->wrap()
                    ->description(fn (BankStatementLine $l) => $l->journalLine?->journal?->entry_date?->toDateString()),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['unmatched' => 'Unmatched', 'matched' => 'Matched', 'adjusted' => 'Adjusted'])])
            ->actions([
                Tables\Actions\Action::make('match')->label('Match')->visible(fn (BankStatementLine $l) => $editable() && $l->status === 'unmatched')
                    ->form(fn (BankStatementLine $l) => [Forms\Components\Select::make('journal_line_id')->label('GL entry')->required()->searchable()
                        ->options($this->svc()->unclearedQuery($l->statement->bankAccount)->get()
                            ->sortBy(fn ($jl) => [Money::of($jl->debit)->minus(Money::of($jl->credit))->isEqualTo(Money::of($l->amount)) ? 0 : 1, $jl->journal->entry_date->toDateString()])
                            ->mapWithKeys(fn ($jl) => [$jl->id => $jl->journal->entry_date->toDateString().' · '.ReportService::d2(Money::of($jl->debit)->minus(Money::of($jl->credit))).' · '.$jl->journal->memo])->all())])
                    ->action(fn (BankStatementLine $l, array $data) => $this->run(fn () => $this->svc()->match($l, (int) $data['journal_line_id']), 'Matched')),
                Tables\Actions\Action::make('adjust')->label('Post adjustment')->color('gray')->visible(fn (BankStatementLine $l) => $editable() && $l->status === 'unmatched')
                    ->modalDescription('For bank-only items (fees, interest, NSF): posts a journal between the bank account and the account you choose, then matches it.')
                    ->form([
                        Forms\Components\Select::make('account')->label('Other account')->required()->searchable()
                            ->options(fn () => Account::where('active', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->code => "{$a->code} {$a->name}"])->all()),
                        Forms\Components\TextInput::make('memo'),
                    ])
                    ->action(fn (BankStatementLine $l, array $data) => $this->run(fn () => $this->svc()->adjust($l, $data['account'], $data['memo'] ?? null), 'Adjustment posted')),
                Tables\Actions\Action::make('unmatch')->label('Unmatch')->color('danger')->visible(fn (BankStatementLine $l) => $editable() && $l->status === 'matched')
                    ->action(fn (BankStatementLine $l) => $this->run(fn () => $this->svc()->unmatch($l), 'Unmatched')),
            ]);
    }
}
