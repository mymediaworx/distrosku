<?php

namespace App\Filament\Pages;

use App\Accounting\DunningService;
use App\Models\DunningBatch;
use App\Models\DunningNotice;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Overdue reminders. "Run dunning" makes a batch of DRAFT notices and a statement PDF batch.
 * The ERP never emails (0.20.17 answer: dunning needs NO SMTP / mail credentials). Each notice can be printed or saved as a PDF letter,
 * or downloaded as an unsent .eml draft and sent from your own mailbox. The page says so when the server has no real mail transport.
 */
class Dunning extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.table-page';

    protected static ?string $navigationGroup = 'Accounting';

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $title = 'Dunning (overdue reminders)';

    protected static ?string $slug = 'accounting/dunning';

    protected static ?int $navigationSort = 45;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && $u->canModule('dunning');
    }

    /** True when the server has no real mail transport (log / array / default local SMTP). Dunning is unaffected; this only explains the page. */
    public static function emailNotConfigured(): bool
    {
        $m = (string) config('mail.default');

        return in_array($m, ['log', 'array'], true) || ($m === 'smtp' && in_array((string) config('mail.mailers.smtp.host'), ['', '127.0.0.1', 'localhost'], true));
    }

    public function intro(): string
    {
        $lv = collect(DunningService::levels())->map(fn ($l) => "Level {$l['level']} \u{2013} ".e($l['name'])." ({$l['days']}+ days late)")->implode(' · ');

        $notice = static::emailNotConfigured()
            ? '<p class="mb-2 font-semibold text-warning-600" data-test="email-not-configured">Email is not configured on this server. That is fine: dunning never sends email itself. '
                .'Use <em>Print / PDF</em> on a notice (or the statement batch PDF) to mail or hand it over, or <em>Download draft (.eml)</em> to send from your own mailbox.</p>'
            : '';

        return $notice.'Due date = invoice date + the customer\'s terms (Net N). '.$lv.'. Notices are <strong>drafts only</strong>: nothing is emailed by the ERP. '
            .'Download a notice as an unsent email draft (.eml) to review and send from your own mailbox, or print the statement batch.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run')->label('Run dunning')->icon('heroicon-o-play')
                ->form([Forms\Components\DatePicker::make('as_of')->default(now())->required()])
                ->action(function (array $data) {
                    $b = app(DunningService::class)->run($data['as_of'], auth()->user());
                    Notification::make()->success()->title("Batch #{$b->id}: {$b->notice_count} draft notice(s)")->send();
                }),
            Action::make('statements')->label('Statement batch PDF')->icon('heroicon-o-document-arrow-down')
                ->form([Forms\Components\Select::make('batch')->required()->default(fn () => DunningBatch::latest('id')->value('id'))
                    ->options(fn () => DunningBatch::latest('id')->limit(30)->get()->mapWithKeys(fn ($b) => [$b->id => "#{$b->id} as of {$b->as_of->toDateString()} ({$b->notice_count})"]))])
                ->action(fn (array $data) => response()->streamDownload(fn () => print (app(DunningService::class)->statementsPdf(DunningBatch::findOrFail($data['batch']))),
                    "dunning-statements-{$data['batch']}.pdf", ['Content-Type' => 'application/pdf'])),
            Action::make('levels')->label('Levels')->icon('heroicon-o-adjustments-horizontal')->color('gray')
                ->fillForm(fn () => ['levels' => DunningService::levels()])
                ->form([Forms\Components\Repeater::make('levels')->columns(2)->schema([
                    Forms\Components\TextInput::make('days')->label('Days past due')->integer()->minValue(1)->required(),
                    Forms\Components\TextInput::make('name')->required()->maxLength(60),
                ])->minItems(1)->maxItems(6)])
                ->action(fn (array $data) => DunningService::saveLevels($data['levels'])),
        ];
    }

    /** Printable PDF letter for one notice (no email needed). */
    public static function noticePdf(DunningNotice $n): string
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('docs.dunning-notice', ['n' => $n->loadMissing('customer'), 'company' => \App\Models\Company::current(), 'info' => config('erp.company')])
            ->setPaper('letter')->output();
    }

    public static function eml(DunningNotice $n): string
    {
        $from = config('mail.from.address', 'billing@example.com');
        $headers = "X-Unsent: 1\r\nTo: ".($n->to_email ?: '')."\r\nFrom: $from\r\nSubject: ".str_replace(["\r", "\n"], ' ', $n->subject)
            ."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";

        return $headers.str_replace("\n", "\r\n", $n->body)."\r\n";
    }

    public function table(Table $table): Table
    {
        return $table->query(DunningNotice::query()->with('customer', 'batch'))->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('batch.id')->label('Batch')->prefix('#'),
                Tables\Columns\TextColumn::make('customer.name')->searchable(),
                Tables\Columns\TextColumn::make('level')->badge()->color(fn ($state) => $state >= 3 ? 'danger' : ($state == 2 ? 'warning' : 'gray')),
                Tables\Columns\TextColumn::make('max_days_overdue')->label('Days late'),
                Tables\Columns\TextColumn::make('overdue_amount')->money('USD'),
                Tables\Columns\TextColumn::make('to_email')->label('To')->placeholder('no email on file'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('dunning_batch_id')->label('Batch')->options(fn () => DunningBatch::latest('id')->limit(30)->pluck('id', 'id')->map(fn ($i) => "#$i")),
                Tables\Filters\SelectFilter::make('status')->options(['draft' => 'Draft', 'reviewed' => 'Reviewed', 'dismissed' => 'Dismissed']),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')->label('Preview')->modalSubmitAction(false)
                    ->modalHeading(fn (DunningNotice $n) => $n->subject)
                    ->modalContent(fn (DunningNotice $n) => new \Illuminate\Support\HtmlString('<pre class="whitespace-pre-wrap text-sm">'.e($n->body).'</pre>')),
                Tables\Actions\Action::make('pdf')->label('Print / PDF')->icon('heroicon-o-printer')
                    ->action(fn (DunningNotice $n) => response()->streamDownload(fn () => print (self::noticePdf($n)), "dunning-{$n->id}-{$n->customer->code}.pdf", ['Content-Type' => 'application/pdf'])),
                Tables\Actions\Action::make('eml')->label('Download draft (.eml)')->icon('heroicon-o-envelope')
                    ->action(fn (DunningNotice $n) => response()->streamDownload(fn () => print (self::eml($n)), "dunning-{$n->id}-{$n->customer->code}.eml", ['Content-Type' => 'message/rfc822'])),
                Tables\Actions\Action::make('reviewed')->label('Mark reviewed')->visible(fn (DunningNotice $n) => $n->status === 'draft')
                    ->action(fn (DunningNotice $n) => $n->update(['status' => 'reviewed'])),
                Tables\Actions\Action::make('dismiss')->label('Dismiss')->color('gray')->visible(fn (DunningNotice $n) => $n->status !== 'dismissed')
                    ->action(fn (DunningNotice $n) => $n->update(['status' => 'dismissed'])),
            ]);
    }
}
