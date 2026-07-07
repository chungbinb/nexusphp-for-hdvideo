<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\ShopCategoryResource\Pages\ManageShopCategories;
use App\Models\ShopCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShopCategoryResource extends Resource
{
    protected static ?string $model = ShopCategory::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-tag';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 26;

    public static function getNavigationLabel(): string
    {
        return '商城商品类型管理';
    }

    public static function getBreadcrumb(): string
    {
        return '商城商品类型管理';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('类型标识')
                    ->helperText('用于前台链接和商品归类，建议使用英文、数字、下划线。')
                    ->required()
                    ->maxLength(40)
                    ->rules(['regex:/^[a-zA-Z0-9_-]+$/']),
                TextInput::make('name')
                    ->label('类型名称')
                    ->required()
                    ->maxLength(100),
                TextInput::make('sort')
                    ->label('排序（越小越靠前）')
                    ->numeric()
                    ->default(0),
                Toggle::make('enabled')
                    ->label('前台显示')
                    ->default(true),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')->label('类型名称')->searchable(),
                TextColumn::make('code')->label('类型标识')->searchable()->badge(),
                TextColumn::make('enabled')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? '显示' : '隐藏')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
                TextColumn::make('sort')->label('排序')->sortable(),
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
            'index' => ManageShopCategories::route('/'),
        ];
    }
}
