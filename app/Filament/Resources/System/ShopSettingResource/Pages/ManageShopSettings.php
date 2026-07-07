<?php

namespace App\Filament\Resources\System\ShopSettingResource\Pages;

use App\Filament\Resources\System\ShopSettingResource;
use App\Models\ShopSetting;
use Filament\Resources\Pages\ManageRecords;

class ManageShopSettings extends ManageRecords
{
    protected static string $resource = ShopSettingResource::class;

    public function mount(): void
    {
        ShopSetting::ensureSchema();
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }
}
