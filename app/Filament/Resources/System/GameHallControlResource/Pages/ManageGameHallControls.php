<?php

namespace App\Filament\Resources\System\GameHallControlResource\Pages;

use App\Filament\Resources\System\GameHallControlResource;
use Filament\Resources\Pages\ManageRecords;

class ManageGameHallControls extends ManageRecords
{
    protected static string $resource = GameHallControlResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }
}
