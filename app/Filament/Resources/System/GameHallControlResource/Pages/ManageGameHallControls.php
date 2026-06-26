<?php

namespace App\Filament\Resources\System\GameHallControlResource\Pages;

use App\Filament\Resources\System\GameHallControlResource;
use App\Models\GameHallControl;
use Filament\Resources\Pages\ManageRecords;

class ManageGameHallControls extends ManageRecords
{
    protected static string $resource = GameHallControlResource::class;

    public function mount(): void
    {
        GameHallControl::ensureSchema();
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
