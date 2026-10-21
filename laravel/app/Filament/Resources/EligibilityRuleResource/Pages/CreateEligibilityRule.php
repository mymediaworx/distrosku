<?php

namespace App\Filament\Resources\EligibilityRuleResource\Pages;

use App\Filament\Resources\EligibilityRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEligibilityRule extends CreateRecord
{
    protected static string $resource = EligibilityRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['updated_by' => auth()->id(), 'version' => 1];
    }
}
