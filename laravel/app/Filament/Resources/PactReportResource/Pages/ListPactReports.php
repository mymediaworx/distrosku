<?php

namespace App\Filament\Resources\PactReportResource\Pages;

use App\Filament\Resources\PactReportResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPactReports extends ListRecords
{
    protected static string $resource = PactReportResource::class;

    protected function getHeaderActions(): array
    {
        return [PactReportResource::generateAction(Action::class)];
    }
}
