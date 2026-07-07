<?php

namespace App\Filament\Resources\System\ShopOrderResource\Pages;

use App\Filament\Resources\System\ShopOrderResource;
use App\Models\ShopOrder;
use Filament\Resources\Pages\ManageRecords;

class ManageShopOrders extends ManageRecords
{
    protected static string $resource = ShopOrderResource::class;

    public function mount(): void
    {
        ShopOrder::ensureSchema();
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
