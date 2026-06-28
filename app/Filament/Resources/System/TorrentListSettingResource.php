<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
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
                TextInput::make('sticky_count')
                    ->label('置顶种子显示数量')
                    ->helperText('种子列表顶部最多展示多少个置顶种子；超出的置顶按普通最新排。填 0 表示不置顶。不设置默认 5。')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(5)
                    ->required(),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sticky_count')
                    ->label('置顶显示数量')
                    ->badge()
                    ->color('info'),
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
