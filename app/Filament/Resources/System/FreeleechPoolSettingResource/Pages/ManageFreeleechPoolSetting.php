<?php

namespace App\Filament\Resources\System\FreeleechPoolSettingResource\Pages;

use App\Filament\Resources\System\FreeleechPoolSettingResource;
use App\Models\FreeleechPoolSetting;
use Filament\Resources\Pages\ManageRecords;

class ManageFreeleechPoolSetting extends ManageRecords
{
    protected static string $resource = FreeleechPoolSettingResource::class;

    public function mount(): void
    {
        FreeleechPoolSetting::ensureSchema();
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
