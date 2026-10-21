<?php

namespace App\Filament\Resources\PactRegistrationResource\Pages;

use App\Filament\Resources\PactRegistrationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPactRegistrations extends ListRecords
{
    protected static string $resource = PactRegistrationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
