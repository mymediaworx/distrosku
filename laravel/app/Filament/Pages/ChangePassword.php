<?php

namespace App\Filament\Pages;

use App\Exceptions\WorkflowException;
use App\Security\PasswordAdmin;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** Phase 16: change own password; forced after an admin reset (must_change_password). */
class ChangePassword extends Page
{
    protected static ?string $slug = 'change-password';

    protected static ?string $title = 'Change password';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.change-password';

    public string $current = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function save(PasswordAdmin $svc): void
    {
        $this->validate(['current' => 'required', 'password' => 'required|min:'.PasswordAdmin::MIN.'|confirmed']);
        try {
            $svc->changeOwn(auth()->user(), $this->current, $this->password);
        } catch (WorkflowException $e) {
            $this->addError('password', $e->getMessage());

            return;
        }
        session()->put('password_hash_'.filament()->getAuthGuard(), auth()->user()->fresh()->getAuthPassword()); // keep this session
        $this->reset('current', 'password', 'password_confirmation');
        Notification::make()->success()->title('Password changed.')->send();
        $this->redirect(filament()->getUrl());
    }
}
