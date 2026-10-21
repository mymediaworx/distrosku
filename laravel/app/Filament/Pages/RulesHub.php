<?php

namespace App\Filament\Pages;

use App\Models\RulesSync;
use App\Models\Setting;
use App\RulesHub\RulesHubClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Rules Hub sync status. The client only sends a GET with install_id and app_version; never customer data. */
class RulesHub extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.rules-hub';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static ?string $slug = 'rules-hub';

    protected static ?string $title = 'Rules Hub';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('rules_hub', 'view');
    }

    public function status(): array
    {
        $c = app(RulesHubClient::class);

        return ['Installed version' => $c->currentVersion() ?? 'none', 'Last successful sync' => Setting::get('rules_hub_synced_at', 'never'),
            'Package URL' => $c->url() ?: 'not configured', 'Public key' => $c->publicKey() ? 'configured' : 'not configured',
            'Install id' => Setting::installId(), 'Schedule' => 'nightly 02:30 (requires the cron: php artisan schedule:run)'];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('sync')->label('Sync now')->icon('heroicon-o-arrow-path')
            ->visible(fn () => auth()->user()?->canModule('rules_hub', 'manage'))
            ->action(function () {
                $s = app(RulesHubClient::class)->sync('manual');
                Notification::make()->status($s->status === 'failed' ? 'danger' : 'success')->title("Sync {$s->status}")->body($s->message)->send();
            })];
    }

    public function table(Table $table): Table
    {
        return $table->query(RulesSync::query()->latest('id'))->columns([
            Tables\Columns\TextColumn::make('created_at')->dateTime()->label('When'),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                'ok' => 'success', 'failed' => 'danger', default => 'gray'
            }),
            Tables\Columns\TextColumn::make('version'),
            Tables\Columns\TextColumn::make('rules_upserted')->label('Rules'),
            Tables\Columns\TextColumn::make('rates_upserted')->label('Rates'),
            Tables\Columns\TextColumn::make('trigger'),
            Tables\Columns\TextColumn::make('message')->limit(80)->wrap(),
        ]);
    }
}
