<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\System\ScratchPrizeResource\Pages\ManageScratchPrizes;
use App\Models\ScratchPrize;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class ScratchPrizeResource extends Resource
{
    protected static ?string $model = ScratchPrize::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-ticket';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 21;

    public static function getNavigationLabel(): string
    {
        return '刮刮乐奖品设置';
    }

    public static function getBreadcrumb(): string
    {
        return '刮刮乐奖品设置';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('奖项名称（如：1000电影票、50G上传量、改名卡、彩色昵称）')
                    ->required()
                    ->maxLength(60),
                Select::make('reward_type')
                    ->label('奖励类型')
                    ->options(ScratchPrize::rewardTypes())
                    ->default('none')
                    ->required()
                    ->native(false),
                TextInput::make('amount')
                    ->label('数量（电影票=张数；上传/下载=GB；谢谢惠顾/实物卡类填 0）')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                TextInput::make('weight')
                    ->label('权重（数值越大越容易刮中；概率=本项权重÷所有启用项权重之和）')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(1),
                TextInput::make('sort')
                    ->label('排序（越小越靠前）')
                    ->numeric()
                    ->default(0),
                Toggle::make('is_enabled')
                    ->label('启用（停用后不会刮出，也不计入概率）')
                    ->default(true),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('奖项'),
                TextColumn::make('reward_type')
                    ->label('类型')
                    ->formatStateUsing(fn ($state) => ScratchPrize::rewardTypes()[$state] ?? $state),
                TextColumn::make('amount')
                    ->label('数量')
                    ->formatStateUsing(fn ($state, ScratchPrize $record) => ScratchPrize::amountLabel($record)),
                TextColumn::make('weight')->label('权重'),
                TextColumn::make('probability')
                    ->label('概率')
                    ->state(function (ScratchPrize $record) {
                        $total = ScratchPrize::enabledWeightTotal();
                        if (! $record->is_enabled || $total <= 0) {
                            return '—';
                        }
                        return round($record->weight / $total * 100, 2) . '%';
                    }),
                TextColumn::make('is_enabled')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? '启用' : '停用')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
                TextColumn::make('sort')->label('排序'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageScratchPrizes::route('/'),
        ];
    }
}
