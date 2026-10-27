<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CustomerResource;
use App\Licensing\LicenseConflictScanner;
use App\Models\Customer;
use App\Models\LicenseConflict;
use App\Models\LicenseType;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * 0.20.17 office review: step through license / account conflicts one at a time (approve / edit / skip).
 * Conflicts are resolved by manual research; attach the license documents to the license record here.
 */
class LicenseConflictReview extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.license-conflict-review';

    protected static ?string $navigationGroup = 'Customers';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $title = 'License conflicts';

    protected static ?string $slug = 'customers/license-conflicts';

    protected static ?int $navigationSort = 4;

    public ?int $conflictId = null;

    public ?array $data = [];

    public ?string $note = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('customer_licenses', 'manage');
    }

    public function mount(): void
    {
        $this->load();
    }

    public function current(): ?LicenseConflict
    {
        return $this->conflictId ? LicenseConflict::with(['customer', 'license'])->find($this->conflictId) : null;
    }

    /** @return array{open:int,position:int,done:int} */
    public function progress(): array
    {
        $open = LicenseConflict::queue()->count();
        $done = LicenseConflict::whereIn('status', ['approved', 'edited'])->count();

        return ['open' => $open, 'position' => $this->conflictId ? 1 : 0, 'done' => $done];
    }

    public function load(?int $after = null): void
    {
        $q = LicenseConflict::queue();
        if ($after) { // never show the one just skipped straight back
            $q->where('id', '!=', $after);
        }
        $c = $q->with('license')->first() ?? ($after ? LicenseConflict::queue()->with('license')->first() : null);
        $this->conflictId = $c?->id;
        $this->note = $c?->note;
        $this->form->fill($c?->license ? $c->license->only(['type', 'site_id', 'number', 'state', 'expires_at', 'research_note', 'documents']) : []);
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Select::make('type')->label('License type')->options(fn () => LicenseType::options(null, false))->required(),
            Forms\Components\TextInput::make('site_id')->label('Site ID')->maxLength(40),
            Forms\Components\TextInput::make('number')->label('License #'),
            Forms\Components\TextInput::make('state')->required()->maxLength(2),
            Forms\Components\DatePicker::make('expires_at')->label('Expires'),
            Forms\Components\Textarea::make('research_note')->label('Research note')->rows(2)->columnSpanFull(),
            Forms\Components\FileUpload::make('documents')->label('License documents (PDF / images)')->multiple()
                ->disk('local')->directory('license-documents')->visibility('private')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->maxSize(10240)
                ->downloadable()->openable()->reorderable()->columnSpanFull(),
        ])->columns(['default' => 1, 'md' => 2]);
    }

    public function customerLinks(): array
    {
        $c = $this->current();
        if (! $c) {
            return [];
        }
        $ids = collect($c->related_customer_ids ?: [])->push($c->customer_id)->filter()->unique();

        return Customer::whereIn('id', $ids)->get()->map(fn ($x) => ['label' => "{$x->code} {$x->name}", 'url' => CustomerResource::getUrl('edit', ['record' => $x])])->all();
    }

    public function approve(): void
    {
        $this->decide('approved');
    }

    public function skip(): void
    {
        $this->decide('skipped');
    }

    /** Save the license edits / attachments, then mark the conflict edited. */
    public function saveEdits(): void
    {
        $c = $this->current();
        abort_unless(static::canAccess() && $c, 403);
        $l = $c->license;
        if (! $l) {
            Notification::make()->title('This conflict has no license row to edit. Use the customer link, then Approve.')->warning()->send();

            return;
        }
        $d = $this->form->getState();
        $site = trim((string) ($d['site_id'] ?? '')) ?: null;
        $num = trim((string) ($d['number'] ?? '')) ?: null;
        if (! $site && ! $num) {
            $this->addError('data.number', 'Enter a Site ID and/or License #.');

            return;
        }
        $l->update(['type' => $d['type'], 'site_id' => $site, 'number' => $num, 'state' => strtoupper(trim((string) $d['state'])),
            'expires_at' => $d['expires_at'] ?: null, 'research_note' => $d['research_note'] ?: null, 'documents' => array_values($d['documents'] ?? []) ?: null]);
        $this->decide('edited');
    }

    private function decide(string $status): void
    {
        abort_unless(static::canAccess(), 403);
        $c = $this->current();
        if (! $c) {
            return;
        }
        app(LicenseConflictScanner::class)->resolve($c, $status, $this->note, auth()->user());
        Notification::make()->title(['approved' => 'Approved', 'edited' => 'Saved', 'skipped' => 'Skipped — it comes back at the end'][$status])->success()->send();
        $this->load($status === 'skipped' ? $c->id : null);
    }

    public function rescan(): void
    {
        abort_unless(static::canAccess(), 403);
        $r = app(LicenseConflictScanner::class)->scan();
        Notification::make()->title("Scan done: {$r['found']} found, {$r['new']} new, {$r['cleared']} cleared")->success()->send();
        $this->load();
    }
}
