<?php

namespace App\Filament\Resources\System\AvatarFrameResource\Pages;

use App\Filament\Resources\System\AvatarFrameResource;
use App\Models\AvatarFrame;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAvatarFrames extends ManageRecords
{
    protected static string $resource = AvatarFrameResource::class;

    protected function getHeaderActions(): array
    {
        AvatarFrame::ensureSchema();

        return [
            CreateAction::make()->label('创建头像挂件'),
        ];
    }
}
