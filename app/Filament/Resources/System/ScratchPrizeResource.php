<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

    public static function multiplierLabel($state): string
    {
        $m = (int) $state;
        if ($m === 0) {
            return '谢谢惠顾';
        }
        if ($m === 1) {
            return '回本（1倍）';
        }
        return $m . ' 倍';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('multiplier')
                    ->label('返还倍数（0=谢谢惠顾，1=回本，2=刮中得2倍面额…）')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(0),
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
                TextColumn::make('multiplier')
                    ->label('奖项')
                    ->formatStateUsing(fn ($state) => self::multiplierLabel($state)),
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
