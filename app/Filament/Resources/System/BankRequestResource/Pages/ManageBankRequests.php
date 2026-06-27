<?php

namespace App\Filament\Resources\System\BankRequestResource\Pages;

use App\Filament\Resources\System\BankRequestResource;
use Filament\Resources\Pages\ManageRecords;

class ManageBankRequests extends ManageRecords
{
    protected static string $resource = BankRequestResource::class;

    public function mount(): void
    {
        try {
            require_once base_path('include/bank.php');
            if (function_exists('bank_ensure_tables')) {
                bank_ensure_tables();
            }
        } catch (\Throwable $e) {
            // 表已存在/建表失败都不应阻塞页面加载
        }
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
