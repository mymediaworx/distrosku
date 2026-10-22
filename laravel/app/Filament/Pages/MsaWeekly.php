<?php

namespace App\Filament\Pages;

use App\Models\MsaSubmission;
use App\Msa\Layout;
use App\Msa\MsaSettings;
use App\Msa\MulticatBuilder;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

/** MSA MULTICAT weekly file: settings, pre-check, preview, generate + archive, download. Admin-level ('msa' module). */
class MsaWeekly extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.msa-weekly';

    protected static ?string $navigationGroup = 'Tax Filings';

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-up';

    protected static ?string $title = 'MSA weekly (MULTICAT)';

    protected static ?string $slug = 'msa/weekly';

    protected static ?int $navigationSort = 20;

    public ?string $week_ending = null;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) $u && $u->canModule('msa');
    }

    public function mount(): void
    {
        $this->week_ending = request()->query('week_ending', MulticatBuilder::defaultWeekEnding());
        $vals = MsaSettings::all();
        foreach (['msa_test_mode', 'msa_report_dollars', 'msa_altria_fields'] as $b) {
            $vals[$b] = MsaSettings::bool($b);
        }
        $this->form->fill($vals);
    }

    public function form(Form $form): Form
    {
        $txt = fn ($k) => Forms\Components\TextInput::make($k)->label(MsaSettings::KEYS[$k][0]);

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('MSA settings')->collapsible()->collapsed(fn () => filled(MsaSettings::get('msa_distributor_id')))->schema([
                $txt('msa_distributor_id')->maxLength(8), $txt('msa_name')->maxLength(32), $txt('msa_address')->maxLength(90),
                $txt('msa_city')->maxLength(25), $txt('msa_state')->maxLength(2), $txt('msa_zip')->maxLength(10),
                $txt('msa_contact_first')->maxLength(20), $txt('msa_contact_last')->maxLength(20), $txt('msa_phone'), $txt('msa_fax'), $txt('msa_email')->email(),
                Forms\Components\Select::make('msa_week_end_day')->label('Week ends on')->options(MsaSettings::DAYS)->required(),
                Forms\Components\Toggle::make('msa_test_mode')->label('Test file (T) - keep on until MSA approves Live'),
                Forms\Components\Toggle::make('msa_report_dollars')->label('Report dollars sold (002)'),
                Forms\Components\Toggle::make('msa_altria_fields')->label('Altria STARS fields (transaction date, 004/005/006 returns)'),
                Forms\Components\Select::make('msa_tax_jurisdiction')->label('State tax jurisdiction')->options(['none' => 'Not reported', 'sid' => 'Ship-to state in SID'])->required(),
                Forms\Components\Select::make('msa_line_ending')->label('Line ending')->options(['crlf' => 'CRLF (Windows)', 'lf' => 'LF'])->required(),
            ])->columns(3),
        ]);
    }

    public function saveSettings(): void
    {
        MsaSettings::put($this->form->getState());
        Notification::make()->success()->title('MSA settings saved')->send();
    }

    public function getReport(): array
    {
        try {
            return app(MulticatBuilder::class)->build((string) $this->week_ending);
        } catch (\Throwable $e) {
            report($e);

            return ['errors' => ['Could not build: '.$e->getMessage()], 'warnings' => [], 'preview' => ['bid' => [], 'sid' => [], 'pur' => []], 'totals' => [], 'lines' => [], 'file_name' => ''];
        }
    }

    public function submissions()
    {
        return MsaSubmission::latest('id')->limit(20)->get();
    }

    public function download(int $id)
    {
        $s = MsaSubmission::findOrFail($id);

        return response()->download(Storage::disk('local')->path($s->path), $s->file_name, ['Content-Type' => 'text/plain']);
    }

    public function markUploaded(int $id): void
    {
        MsaSubmission::whereKey($id)->update(['status' => 'uploaded', 'uploaded_at' => now()]);
        Notification::make()->success()->title('Marked as uploaded')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fix')->label('Missing MSA data')->color('gray')->url(fn () => MsaFixList::getUrl()),
            Action::make('generate')->label('Generate .TOB')->icon('heroicon-o-document-arrow-down')->requiresConfirmation()
                ->modalDescription(fn () => (MsaSettings::bool('msa_test_mode') ? 'TEST' : 'LIVE').' file for week ending '.$this->week_ending.'. It is archived here; upload it to MSA yourself.')
                ->action(function () {
                    try {
                        $s = app(MulticatBuilder::class)->generate((string) $this->week_ending, auth()->id());
                    } catch (\RuntimeException $e) {
                        Notification::make()->danger()->title('Not generated')->body($e->getMessage())->send();

                        return null;
                    }
                    Notification::make()->success()->title("Generated {$s->file_name}")->send();

                    return $this->download($s->id);
                }),
        ];
    }

    public function categories(): array
    {
        return Layout::CATEGORIES;
    }
}
