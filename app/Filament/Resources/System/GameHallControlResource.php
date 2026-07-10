<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
                Select::make('bot_difficulty')
                    ->label('机器人难度')
                    ->helperText('仅炸金花使用。机器人只根据自己的牌和桌面公开信息决策，不会读取其他玩家底牌。')
                    ->options([
                        'simple' => '简单',
                        'hard' => '困难',
                        'hell' => '地狱',
                    ])
                    ->default('simple')
                    ->required()
                    ->native(false)
                    ->visible(fn (?GameHallControl $record): bool => $record?->game_key === 'zjh'),
                Toggle::make('stock_trade_enabled')
                    ->label('允许股票买卖')
                    ->helperText('关闭后仍可查看真实行情和持仓，但不能提交买卖。')
                    ->visible(fn (?GameHallControl $record): bool => $record?->game_key === 'stock'),
                Textarea::make('stock_symbols')
                    ->label('股票池')
                    ->helperText('填写沪深代码，用逗号分隔，例如 SH600519,SZ000001。用户也可按六位代码查询，但只能交易这里配置的股票。')
                    ->rows(4)
                    ->visible(fn (?GameHallControl $record): bool => $record?->game_key === 'stock'),
                TextInput::make('stock_ticket_rate')
                    ->label('电影票换算倍率')
                    ->helperText('成交金额 = 股票价格 × 股数 × 此倍率。')
                    ->numeric()->minValue(0.0001)->step(0.1)->required()
                    ->visible(fn (?GameHallControl $record): bool => $record?->game_key === 'stock'),
                TextInput::make('stock_fee_rate')
                    ->label('单边手续费率')
                    ->helperText('例如 0.001 表示 0.1%，每笔最低收取 1 张电影票。')
                    ->numeric()->minValue(0)->maxValue(0.1)->step(0.0001)->required()
                    ->visible(fn (?GameHallControl $record): bool => $record?->game_key === 'stock'),
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
                TextColumn::make('bot_difficulty')
                    ->label('机器人难度')
                    ->formatStateUsing(fn ($state, GameHallControl $record) => $record->game_key === 'zjh'
                        ? (['simple' => '简单', 'hard' => '困难', 'hell' => '地狱'][$state] ?? '简单')
                        : '—'),
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
