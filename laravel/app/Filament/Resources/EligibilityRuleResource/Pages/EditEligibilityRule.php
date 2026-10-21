<?php

namespace App\Filament\Resources\EligibilityRuleResource\Pages;

use App\Filament\Resources\EligibilityRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEligibilityRule extends EditRecord
{
    protected static string $resource = EligibilityRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()->visible(fn () => EligibilityRuleResource::canDelete($this->record))];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ['updated_by' => auth()->id()] + $data;
    }
}
