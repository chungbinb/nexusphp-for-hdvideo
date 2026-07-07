<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\ShopProductResource\Pages\ManageShopProducts;
use App\Models\ShopProduct;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShopProductResource extends Resource
{
    protected static ?string $model = ShopProduct::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-gift';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 26;

    public static function getNavigationLabel(): string
    {
        return '商城商品管理';
    }

    public static function getBreadcrumb(): string
    {
        return '商城商品管理';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('商品类型')
                    ->options(ShopProduct::typeOptions())
                    ->required()
                    ->native(false),
                TextInput::make('name')
                    ->label('商品名称')
                    ->required()
                    ->maxLength(100),
                TextInput::make('price')
                    ->label('价格（电影票）')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(0),
                TextInput::make('stock')
                    ->label('库存（留空=不限）')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('sort')
                    ->label('排序（越小越靠前）')
                    ->numeric()
                    ->default(0),
                Toggle::make('enabled')
                    ->label('上架')
                    ->default(true),
                Textarea::make('description')
                    ->label('说明')
                    ->rows(4),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')->label('商品')->searchable(),
                TextColumn::make('type_text')->label('类型')->badge(),
                TextColumn::make('price')->label('价格')->formatStateUsing(fn ($state) => number_format((float)$state, 1) . ' 电影票'),
                TextColumn::make('stock_text')->label('库存'),
                TextColumn::make('enabled')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? '上架' : '下架')
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
            'index' => ManageShopProducts::route('/'),
        ];
    }
}
