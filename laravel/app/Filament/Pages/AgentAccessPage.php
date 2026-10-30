<?php

namespace App\Filament\Pages;

use App\Agent\AgentAccess;
use App\Audit\Audit;
use App\Models\AgentAction;
use App\Models\AgentToken;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** #35 Stage 1: Settings > AI agent (owner/admin): on/off switch, access tokens, and the log of what the agent looked up. */
class AgentAccessPage extends Page
{
    protected static string $view = 'filament.pages.agent-access';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'AI agent';

    protected static ?string $title = 'AI agent';

    protected static ?string $slug = 'settings/ai-agent';

    protected static ?int $navigationSort = 90;

    public ?string $newToken = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggle')->label(fn () => AgentAccess::enabled() ? 'Turn agent access OFF' : 'Turn agent access ON')
                ->color(fn () => AgentAccess::enabled() ? 'danger' : 'success')->requiresConfirmation()
                ->modalDescription(fn () => AgentAccess::enabled() ? 'Every token stops working immediately.' : 'Tokens below can then read customers, products, prices, orders and AR (read-only) with their user\'s permissions. Data goes to the AI provider the token is used with.')
                ->action(function () {
                    $on = ! AgentAccess::enabled();
                    Setting::put('agent_enabled', $on ? '1' : '0');
                    Audit::action('agent_access', null, 'AI agent access turned '.($on ? 'ON' : 'OFF'));
                }),
            Action::make('token')->label('New access token')->icon('heroicon-o-key')
                ->form([
                    Forms\Components\Select::make('user_id')->label('Acts as user')->required()->searchable()
                        ->options(fn () => User::where('active', true)->orderBy('name')->pluck('name', 'id'))
                        ->helperText('The agent sees exactly what this user may see. A dedicated user (e.g. "AI agent", Sales role) is best.'),
                    Forms\Components\TextInput::make('name')->label('Label')->required()->maxLength(80)->placeholder('Claude for Michael'),
                    Forms\Components\Select::make('days')->label('Expires after')->options([30 => '30 days', 90 => '90 days', 365 => '1 year'])->default(90)->required(),
                ])
                ->action(function (array $data) {
                    $r = app(AgentAccess::class)->issue(User::findOrFail($data['user_id']), $data['name'], auth()->user(), (int) $data['days']);
                    $this->newToken = $r['plain'];
                    Notification::make()->success()->title('Token created. Copy it now: it is shown only once.')->send();
                }),
        ];
    }

    public function revoke(int $id): void
    {
        $t = AgentToken::findOrFail($id);
        app(AgentAccess::class)->revoke($t, auth()->user());
        Notification::make()->success()->title("Token \"{$t->name}\" revoked")->send();
    }

    protected function getViewData(): array
    {
        return [
            'enabled' => AgentAccess::enabled(),
            'endpoint' => url('/mcp'),
            'tokens' => AgentToken::with('user')->latest('id')->get(),
            'actions' => AgentAction::with('user')->latest('id')->limit(50)->get(),
        ];
    }
}
