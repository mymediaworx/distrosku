<?php

namespace App\Filament\Resources\InventoryCountResource\Pages;

use App\Filament\Resources\InventoryCountResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryCount extends ViewRecord
{
    protected static string $resource = InventoryCountResource::class;

    public function getTitle(): string
    {
        return "Count {$this->record->number} · {$this->record->type} · {$this->record->status}";
    }

    protected function getHeaderActions(): array
    {
        return InventoryCountResource::lifecycleActions(Action::class);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Placeholder::make('info')->content(fn ($record) => ($record->blind ? 'Blind count. ' : '')
                .'Warehouse '.$record->warehouse->code.'. '.($record->notes ?? '')
                .($record->journal_id ? " Journal #{$record->journal_id}." : '')),
        ]);
    }
}
