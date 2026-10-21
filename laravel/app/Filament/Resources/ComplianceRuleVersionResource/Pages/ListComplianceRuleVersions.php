<?php

namespace App\Filament\Resources\ComplianceRuleVersionResource\Pages;

use App\Filament\Resources\ComplianceRuleVersionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListComplianceRuleVersions extends ListRecords
{
    protected static string $resource = ComplianceRuleVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
