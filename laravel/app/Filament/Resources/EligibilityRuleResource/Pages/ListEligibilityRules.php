<?php

namespace App\Filament\Resources\EligibilityRuleResource\Pages;

use App\Filament\Resources\EligibilityRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEligibilityRules extends ListRecords
{
    protected static string $resource = EligibilityRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
