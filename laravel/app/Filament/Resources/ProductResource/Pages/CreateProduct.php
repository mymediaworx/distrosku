<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Catalog\CatalogService;
use App\Catalog\ImageService;
use App\Filament\Resources\ProductResource;
use App\Models\Department;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['sku']) && ! empty($data['department_id'])) {
            $data['sku'] = app(CatalogService::class)->nextSku(Department::findOrFail($data['department_id']));
        }
        $data['image_path'] = app(ImageService::class)->normalizeStored($data['image_path'] ?? null, 'catalog');

        return $data;
    }

    protected function afterCreate(): void
    {
        app(CatalogService::class)->link($this->record, 'admin');
    }
}
