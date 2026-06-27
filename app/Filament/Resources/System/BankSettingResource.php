<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use App\Filament\Resources\System\BankSettingResource\Pages\ManageBankSetting;
use App\Models\BankSetting;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class BankSettingResource extends Resource
{
    protected static ?string $model = BankSetting::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 24;

    public static function getNavigationLabel(): string
    {
        return '魔力银行设置';
    }

    public static function getBreadcrumb(): string
    {
        return '魔力银行设置';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('transfer_enabled')
                    ->label('允许用户给用户转账（关闭后禁止互转）')
                    ->default(true),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transfer_enabled')
                    ->label('用户互转')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? '已开启' : '已关闭')
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBankSetting::route('/'),
        ];
    }
}
