<?php

namespace App\Filament\Resources\MsaDirectoryImportResource\Pages;

use App\Filament\Resources\MsaDirectoryImportResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListMsaDirectoryImports extends ListRecords
{
    protected static string $resource = MsaDirectoryImportResource::class;

    protected function getHeaderActions(): array
    {
        return [MsaDirectoryImportResource::importAction(Action::class)];
    }
}
