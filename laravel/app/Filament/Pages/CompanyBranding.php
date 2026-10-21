<?php

namespace App\Filament\Pages;

use App\Catalog\ImageService;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** Admin-level (owner/admin/brandworx_admin): logo + Employee Quick Tools public link. */
class CompanyBranding extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.company-branding';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $title = 'Branding & quick-tools link';

    protected static ?string $slug = 'branding';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('company', 'manage');
    }

    public function mount(): void
    {
        $this->form->fill(['logo_path' => Company::current()->logo_path]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\FileUpload::make('logo_path')->label('Logo')->image()->disk('public')->directory('branding')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(ImageService::MAX_KB),
        ]);
    }

    public function save(): void
    {
        $path = app(ImageService::class)->normalizeStored($this->form->getState()['logo_path'] ?? null, 'branding');
        Company::current()->update(['logo_path' => $path]);
        Notification::make()->success()->title('Logo saved')->send();
    }

    public function link(): ?string
    {
        return Company::current()->publicUrl();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('token')->label(fn () => Company::current()->public_token ? 'Regenerate link' : 'Generate link')->icon('heroicon-o-link')
            ->requiresConfirmation()->modalDescription('Regenerating disables the old link immediately.')
            ->action(function () {
                Company::current()->rotateToken();
                Notification::make()->success()->title('New quick-tools link generated')->send();
            })];
    }
}
