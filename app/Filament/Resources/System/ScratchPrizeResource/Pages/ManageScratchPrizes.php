<?php

namespace App\Filament\Resources\System\ScratchPrizeResource\Pages;

use App\Filament\Resources\System\ScratchPrizeResource;
use App\Models\ScratchPrize;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
            Action::make('setCost')
                ->label('每张花费：' . ScratchPrize::getCost() . ' 电影票')
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->modalHeading('设置每张刮卡花费')
                ->modalSubmitActionLabel('保存')
                ->schema([
                    TextInput::make('cost')
                        ->label('每张刮卡花费（电影票）')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(ScratchPrize::getCost()),
                ])
                ->action(function (array $data) {
                    ScratchPrize::setCost((int) $data['cost']);
                    Notification::make()->title('已保存，每张花费 ' . (int) $data['cost'] . ' 电影票')->success()->send();
                }),
            CreateAction::make(),
        ];
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }
}
