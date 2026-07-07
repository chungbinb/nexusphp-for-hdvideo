<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\ShopSettingResource\Pages\ManageShopSettings;
use App\Models\ShopSetting;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShopSettingResource extends Resource
{
    protected static ?string $model = ShopSetting::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 25;

    public static function getNavigationLabel(): string
    {
        return '商城设置';
    }

    public static function getBreadcrumb(): string
    {
        return '商城设置';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('enabled')
                    ->label('开启商城入口和访问')
                    ->default(true),
                Select::make('min_class')
                    ->label('允许进入商城的最低用户等级')
                    ->options(ShopSetting::classOptions())
                    ->default(14)
                    ->required()
                    ->native(false),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('enabled')
                    ->label('商城状态')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? '开启' : '关闭')
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
                TextColumn::make('min_class')
                    ->label('最低等级')
                    ->formatStateUsing(fn ($state) => ShopSetting::classOptions()[$state] ?? $state),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShopSettings::route('/'),
        ];
    }
}
