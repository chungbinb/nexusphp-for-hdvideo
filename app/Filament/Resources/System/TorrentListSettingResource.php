<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use App\Filament\Resources\System\TorrentListSettingResource\Pages\ManageTorrentListSetting;
use App\Models\TorrentListSetting;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class TorrentListSettingResource extends Resource
{
    protected static ?string $model = TorrentListSetting::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bars-3-bottom-left';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 25;

    public static function getNavigationLabel(): string
    {
        return '种子列表设置';
    }

    public static function getBreadcrumb(): string
    {
        return '种子列表设置';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('source_tag_id')
                    ->label('“源码”标签 ID')
                    ->helperText('与系统“官方标签”同时存在时，判定为源码官组。本站当前为 8。')
                    ->numeric()
                    ->minValue(1)
                    ->default(8)
                    ->required(),
                TextInput::make('auto_sticky_days')->label('新官组默认置顶天数')->numeric()->minValue(1)->maxValue(365)->default(5)->required(),
                TextInput::make('official_free_hours')->label('官组新种默认 Free 小时')->numeric()->minValue(0)->maxValue(8760)->default(24)->required(),
                TextInput::make('normal_free_hours')->label('普通新种默认 Free 小时')->numeric()->minValue(0)->maxValue(8760)->default(12)->required(),
                Toggle::make('bonus_promotion_enabled')->label('开放魔力推广')->default(true),
                TextInput::make('bonus_sticky_cost')->label('魔力置顶价格')->numeric()->minValue(0.1)->default(10000)->required(),
                TextInput::make('bonus_sticky_days')->label('每次魔力置顶天数')->numeric()->minValue(1)->maxValue(365)->default(5)->required(),
                TextInput::make('bonus_free_cost')->label('魔力 Free 价格')->numeric()->minValue(0.1)->default(10000)->required(),
                TextInput::make('bonus_free_hours')->label('每次魔力 Free 小时')->numeric()->minValue(1)->maxValue(8760)->default(12)->required(),
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('auto_sticky_days')
                    ->label('默认置顶')
                    ->suffix(' 天')
                    ->badge()
                    ->color('info'),
                TextColumn::make('official_free_hours')->label('官组 Free')->suffix(' 小时'),
                TextColumn::make('normal_free_hours')->label('普通 Free')->suffix(' 小时'),
                TextColumn::make('bonus_sticky_cost')->label('置顶价格')->numeric(decimalPlaces: 1),
                TextColumn::make('bonus_free_cost')->label('Free 价格')->numeric(decimalPlaces: 1),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTorrentListSetting::route('/'),
        ];
    }
}
