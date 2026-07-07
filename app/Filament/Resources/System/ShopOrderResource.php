<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\ShopOrderResource\Pages\ManageShopOrders;
use App\Models\ShopOrder;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ShopOrderResource extends Resource
{
    protected static ?string $model = ShopOrder::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 28;

    public static function getNavigationLabel(): string
    {
        return '商城订单管理';
    }

    public static function getBreadcrumb(): string
    {
        return '商城订单管理';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->label('订单状态')
                    ->options(ShopOrder::statusOptions())
                    ->required()
                    ->native(false),
                Textarea::make('note')
                    ->label('后台备注')
                    ->rows(4),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('order_no')->label('订单号')->searchable(),
                TextColumn::make('uid')->label('用户')->formatStateUsing(fn ($state) => username_for_admin($state))->searchable(),
                TextColumn::make('product_name')->label('商品'),
                TextColumn::make('product_type_text')->label('类型')->badge(),
                TextColumn::make('total_price')->label('金额')->formatStateUsing(fn ($state) => number_format((float)$state, 1)),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ShopOrder::statusOptions()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        ShopOrder::STATUS_DELIVERED => 'success',
                        ShopOrder::STATUS_CANCELLED, ShopOrder::STATUS_REFUNDED => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')->label('下单时间'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('订单状态')
                    ->options(ShopOrder::statusOptions()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product']));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageShopOrders::route('/'),
        ];
    }
}
