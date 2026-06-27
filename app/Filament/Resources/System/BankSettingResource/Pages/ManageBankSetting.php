<?php

namespace App\Filament\Resources\System\BankSettingResource\Pages;

use App\Filament\Resources\System\BankSettingResource;
use App\Models\BankSetting;
use Filament\Resources\Pages\ManageRecords;

class ManageBankSetting extends ManageRecords
{
    protected static string $resource = BankSettingResource::class;

    public function mount(): void
    {
        BankSetting::ensureSchema();
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
