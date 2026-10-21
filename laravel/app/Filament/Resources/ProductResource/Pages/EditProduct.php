<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Catalog\CatalogService;
use App\Catalog\ImageService;
use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('archive')->label('Archive')->color('warning')->requiresConfirmation()
                ->visible(fn () => ! $this->record->isArchived() && ProductResource::canArchive())
                ->action(fn () => $this->record->archive(auth()->user())),
            Actions\Action::make('unarchive')->label('Unarchive')
                ->visible(fn () => $this->record->isArchived() && ProductResource::canArchive())
                ->action(fn () => $this->record->unarchive()),
            Actions\DeleteAction::make(),
        ];
    }

    protected ?string $oldPrice = null;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->oldPrice = (string) $this->record->price;
        $data['image_path'] = app(ImageService::class)->normalizeStored($data['image_path'] ?? null, 'catalog');

        return $data;
    }

    protected function afterSave(): void
    {
        // new price (or never linked) => record another price submission for research
        if (! $this->record->shared_product_id || bccomp((string) $this->oldPrice, (string) $this->record->price, 4) !== 0) {
            app(CatalogService::class)->link($this->record, 'admin');
        }
    }
}
