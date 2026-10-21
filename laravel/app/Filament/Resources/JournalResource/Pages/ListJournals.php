<?php

namespace App\Filament\Resources\JournalResource\Pages;

use App\Filament\Resources\JournalResource;
use App\Models\Account;
use App\Services\PostingService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListJournals extends ListRecords
{
    protected static string $resource = JournalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Phase 8: admin-only adjusting entry. Must balance and be dated in an open period.
            Actions\Action::make('manual')->label('Manual entry')->icon('heroicon-o-pencil-square')
                ->visible(fn () => auth()->user()?->isAdmin())
                ->modalWidth('3xl')
                ->form([
                    Forms\Components\DatePicker::make('date')->default(now())->required(),
                    Forms\Components\TextInput::make('memo')->required()->maxLength(200),
                    Forms\Components\Repeater::make('lines')->columns(4)->minItems(2)->defaultItems(2)->schema([
                        Forms\Components\Select::make('account')->required()->searchable()
                            ->options(fn () => Account::where('active', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->code => "{$a->code} {$a->name}"])->all()),
                        Forms\Components\TextInput::make('debit')->numeric()->minValue(0),
                        Forms\Components\TextInput::make('credit')->numeric()->minValue(0),
                        Forms\Components\TextInput::make('memo'),
                    ]),
                ])
                ->action(function (array $data) {
                    try {
                        $j = app(PostingService::class)->manual($data['date'], $data['memo'], array_values($data['lines']), auth()->user());
                        Notification::make()->success()->title("Journal #{$j->id} posted")->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Not posted')->body($e->getMessage())->persistent()->send();
                    }
                }),
        ];
    }
}
