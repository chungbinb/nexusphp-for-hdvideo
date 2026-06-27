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
            Action::make('settings')
                ->label('刮刮乐设置（每张 ' . ScratchPrize::getCost() . ' 票 · 每日 ' . (ScratchPrize::getDailyLimit() === 0 ? '不限' : ScratchPrize::getDailyLimit() . ' 次') . ' · 间隔 ' . ScratchPrize::getCooldown() . ' 秒）')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('warning')
                ->modalHeading('刮刮乐设置')
                ->modalSubmitActionLabel('保存')
                ->schema([
                    TextInput::make('cost')
                        ->label('每张刮卡花费（电影票）')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(ScratchPrize::getCost()),
                    TextInput::make('daily_limit')
                        ->label('每人每日刮卡次数上限（0 = 不限制）')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(ScratchPrize::getDailyLimit()),
                    TextInput::make('cooldown')
                        ->label('两次刮卡最短间隔秒数（0 = 不限制，防连点/脚本）')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(ScratchPrize::getCooldown()),
                ])
                ->action(function (array $data) {
                    ScratchPrize::setCost((int) $data['cost']);
                    ScratchPrize::setDailyLimit((int) $data['daily_limit']);
                    ScratchPrize::setCooldown((int) $data['cooldown']);
                    Notification::make()->title('已保存：每张 ' . (int) $data['cost'] . ' 票，每日 ' . ((int) $data['daily_limit'] === 0 ? '不限' : (int) $data['daily_limit'] . ' 次') . '，间隔 ' . (int) $data['cooldown'] . ' 秒')->success()->send();
                }),
            CreateAction::make(),
        ];
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }
}
