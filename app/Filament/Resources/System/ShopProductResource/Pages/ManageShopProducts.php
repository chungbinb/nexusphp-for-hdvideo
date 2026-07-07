<?php

namespace App\Filament\Resources\System\ShopProductResource\Pages;

use App\Filament\Resources\System\ShopProductResource;
use App\Models\ShopProduct;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageShopProducts extends ManageRecords
{
    protected static string $resource = ShopProductResource::class;

    public function mount(): void
    {
        ShopProduct::ensureSchema();
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
