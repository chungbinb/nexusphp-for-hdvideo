<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\FreeleechPoolSettingResource\Pages\ManageFreeleechPoolSetting;
use App\Models\FreeleechPoolSetting;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FreeleechPoolSettingResource extends Resource
{
    protected static ?string $model = FreeleechPoolSetting::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-gift';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 23;

    public static function getNavigationLabel(): string
    {
        return '站免池设置';
    }

    public static function getBreadcrumb(): string
    {
        return '站免池设置';
    }

    public static function getModelLabel(): string
    {
        return '站免池设置';
    }

    public static function getPluralModelLabel(): string
    {
        return '站免池设置';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Toggle::make('enabled')->label('开放站免池')->helperText('关闭后停止接受投放，并暂停站免池的全站 Free 覆盖。'),
            TextInput::make('goal')->label('本轮目标魔力')->numeric()->minValue(1)->required()->helperText('保存后立即同步到当前正在筹集的轮次。'),
            TextInput::make('duration_hours')->label('达标后全站 Free 时长（小时）')->integer()->minValue(1)->maxValue(720)->required(),
            TextInput::make('min_contribution')->label('单次最低投放魔力')->numeric()->minValue(0.1)->required(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('enabled')->label('状态')->badge()->formatStateUsing(fn($state) => $state ? '已开放' : '已关闭')->color(fn($state) => $state ? 'success' : 'danger'),
            TextColumn::make('goal')->label('目标魔力')->numeric(decimalPlaces: 1),
            TextColumn::make('duration_hours')->label('Free 时长')->suffix(' 小时'),
            TextColumn::make('min_contribution')->label('最低投放')->numeric(decimalPlaces: 1),
            TextColumn::make('updated_at')->label('更新时间'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFreeleechPoolSetting::route('/')];
    }
}
