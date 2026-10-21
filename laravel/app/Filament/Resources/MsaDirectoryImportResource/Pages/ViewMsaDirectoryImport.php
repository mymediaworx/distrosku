<?php

namespace App\Filament\Resources\MsaDirectoryImportResource\Pages;

use App\Filament\Resources\MsaDirectoryImportResource;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Resources\Pages\ViewRecord;

class ViewMsaDirectoryImport extends ViewRecord
{
    protected static string $resource = MsaDirectoryImportResource::class;

    public function form(Form $form): Form
    {
        return $form->schema([Placeholder::make('i')->label('')
            ->content(fn ($record) => "{$record->state} list effective {$record->effective_on->toDateString()} · {$record->rows} entries · ".($record->source ?? ''))]);
    }
}
