<?php

namespace App\Filament\Resources\System\TorrentListSettingResource\Pages;

use App\Filament\Resources\System\TorrentListSettingResource;
use App\Models\TorrentListSetting;
use Filament\Resources\Pages\ManageRecords;

class ManageTorrentListSetting extends ManageRecords
{
    protected static string $resource = TorrentListSettingResource::class;

    public function mount(): void
    {
        TorrentListSetting::ensureSchema();
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
