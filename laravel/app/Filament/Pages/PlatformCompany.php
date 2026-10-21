<?php

namespace App\Filament\Pages;

use App\Catalog\ImageService;
use App\Filament\Concerns\PlatformOnly;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PlatformCompany extends Page implements HasForms
{
    use InteractsWithForms, PlatformOnly;

    protected static string $view = 'filament.pages.settings';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $title = 'Company account';

    protected static ?string $slug = 'platform/company';

    public ?array $data = [];

    public function mount(): void
    {
        $c = Company::current();
        $this->form->fill(['name' => $c->name, 'logo_path' => $c->logo_path]);
    }

    public function form(Form $form): Form
    {
        $c = Company::current();

        return $form->statePath('data')->schema([
            Forms\Components\Placeholder::make('status')->content($c->suspended
                ? "SUSPENDED since {$c->suspended_at?->timezone('America/New_York')->format('Y-m-d H:i T')}: {$c->suspended_reason}" : 'Active'),
            Forms\Components\TextInput::make('name')->required()->maxLength(255),
            Forms\Components\FileUpload::make('logo_path')->label('Logo')->image()->disk('public')->directory('branding')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(ImageService::MAX_KB),
        ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();
        Company::current()->update(['name' => $d['name'], 'logo_path' => app(ImageService::class)->normalizeStored($d['logo_path'] ?? null, 'branding')]);
        Notification::make()->success()->title('Company saved')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')->label('Suspend account')->color('danger')->icon('heroicon-o-no-symbol')
                ->visible(fn () => ! Company::current()->suspended)->requiresConfirmation()
                ->modalDescription('Blocks every login except brandworx_admin, and disables the public quick-tools link.')
                ->form([Forms\Components\TextInput::make('reason')->required()->maxLength(255)])
                ->action(function (array $data) {
                    Company::current()->update(['suspended' => true, 'suspended_reason' => $data['reason'], 'suspended_at' => now()]);
                    Notification::make()->warning()->title('Account suspended')->send();
                }),
            Action::make('reactivate')->label('Reactivate account')->color('success')->icon('heroicon-o-check-circle')
                ->visible(fn () => Company::current()->suspended)->requiresConfirmation()
                ->action(function () {
                    Company::current()->update(['suspended' => false, 'suspended_reason' => null, 'suspended_at' => null]);
                    Notification::make()->success()->title('Account reactivated')->send();
                }),
        ];
    }
}
