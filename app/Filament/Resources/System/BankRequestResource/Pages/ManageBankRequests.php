<?php

namespace App\Filament\Resources\System\BankRequestResource\Pages;

use App\Filament\Resources\System\BankRequestResource;
use Filament\Resources\Pages\ManageRecords;

class ManageBankRequests extends ManageRecords
{
    protected static string $resource = BankRequestResource::class;

    public function mount(): void
    {
        require_once base_path('include/bank.php');
        if (function_exists('bank_ensure_tables')) {
            bank_ensure_tables();
        }
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
