<?php

namespace App\Filament\Resources\System\ScratchPrizeResource\Pages;

use App\Filament\Resources\System\ScratchPrizeResource;
use App\Models\ScratchPrize;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageScratchPrizes extends ManageRecords
{
    protected static string $resource = ScratchPrizeResource::class;

    public function mount(): void
    {
        ScratchPrize::ensureSchema();
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }
}
