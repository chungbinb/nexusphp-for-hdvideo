<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use App\Filament\Resources\System\BankConfigResource\Pages\ManageBankConfig;
use App\Models\BankConfig;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class BankConfigResource extends Resource
{
    protected static ?string $model = BankConfig::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-library';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 22;

    public static function getNavigationLabel(): string
    {
        return '高清银行设置';
    }

    public static function getBreadcrumb(): string
    {
        return '高清银行设置';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('deposit_rate')
                    ->label('存款日利率（%/天，例如 0.1 表示每天 0.1%）')
                    ->numeric()
                    ->minValue(0)
                    ->step('0.0001')
                    ->required(),
                TextInput::make('loan_rate')
                    ->label('贷款日利率（%/天，例如 0.3 表示每天 0.3%）')
                    ->numeric()
                    ->minValue(0)
                    ->step('0.0001')
                    ->required(),
                TextInput::make('max_loan')
                    ->label('单用户最高可借（电影票）')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('min_amount')
                    ->label('单次存入/借款最低金额（电影票）')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('deposit_rate')->label('存款日利率')->formatStateUsing(fn ($s) => $s . ' %/天'),
                TextColumn::make('loan_rate')->label('贷款日利率')->formatStateUsing(fn ($s) => $s . ' %/天'),
                TextColumn::make('max_loan')->label('最高可借')->numeric(),
                TextColumn::make('min_amount')->label('最低金额')->numeric(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBankConfig::route('/'),
        ];
    }
}
