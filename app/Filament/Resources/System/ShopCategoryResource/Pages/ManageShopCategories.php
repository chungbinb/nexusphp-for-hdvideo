<?php

namespace App\Filament\Resources\System\ShopCategoryResource\Pages;

use App\Filament\Resources\System\ShopCategoryResource;
use App\Models\ShopCategory;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShopCategories extends ManageRecords
{
    protected static string $resource = ShopCategoryResource::class;

    public function mount(): void
    {
        ShopCategory::ensureSchema();
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
