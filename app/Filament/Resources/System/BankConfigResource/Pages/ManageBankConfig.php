<?php

namespace App\Filament\Resources\System\BankConfigResource\Pages;

use App\Filament\Resources\System\BankConfigResource;
use App\Models\BankConfig;
use Filament\Resources\Pages\ManageRecords;

class ManageBankConfig extends ManageRecords
{
    protected static string $resource = BankConfigResource::class;

    public function mount(): void
    {
        BankConfig::ensureSchema();
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
