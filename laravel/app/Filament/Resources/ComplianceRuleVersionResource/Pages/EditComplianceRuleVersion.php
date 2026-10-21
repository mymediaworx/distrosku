<?php

namespace App\Filament\Resources\ComplianceRuleVersionResource\Pages;

use App\Filament\Resources\ComplianceRuleVersionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditComplianceRuleVersion extends EditRecord
{
    protected static string $resource = ComplianceRuleVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
