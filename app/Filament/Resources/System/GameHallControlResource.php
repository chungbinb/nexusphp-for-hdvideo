<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use App\Filament\Resources\System\GameHallControlResource\Pages\ManageGameHallControls;
use App\Models\GameHallControl;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class GameHallControlResource extends Resource
{
    protected static ?string $model = GameHallControl::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return '游戏大厅控制';
    }

    public static function getBreadcrumb(): string
    {
        return '游戏大厅控制';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function classOptions(): array
    {
        return [
            13 => '版主 (13)',
            14 => '管理员 (14)',
            15 => '维护/开发 (15)',
            16 => '主管 (16)',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('游戏')->disabled(),
                Toggle::make('is_open')->label('是否开放（关闭后玩家无法进入）'),
                Select::make('min_class')
                    ->label('关闭时仍可进入的最低用户等级（用于开发组预览）')
                    ->options(self::classOptions())
                    ->default(15)
                    ->required()
                    ->native(false),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label('游戏'),
                TextColumn::make('is_open')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? '开放' : '关闭')
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
                TextColumn::make('min_class')
                    ->label('关闭时最低可进等级')
                    ->formatStateUsing(fn ($state) => self::classOptions()[$state] ?? $state),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGameHallControls::route('/'),
        ];
    }
}
