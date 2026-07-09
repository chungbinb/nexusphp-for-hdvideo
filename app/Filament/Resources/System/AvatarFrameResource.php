<?php

namespace App\Filament\Resources\System;

use App\Filament\Resources\System\AvatarFrameResource\Pages\ManageAvatarFrames;
use App\Models\AvatarFrame;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AvatarFrameResource extends Resource
{
    protected static ?string $model = AvatarFrame::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-sparkles';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 28;

    public static function getNavigationLabel(): string
    {
        return '头像挂件管理';
    }

    public static function getBreadcrumb(): string
    {
        return '头像挂件管理';
    }

    public static function form(Schema $schema): Schema
    {
        AvatarFrame::ensureSchema();

        return $schema
            ->components([
                TextInput::make('code')
                    ->label('代码')
                    ->helperText('唯一标识，建议使用英文、数字、下划线。')
                    ->required()
                    ->maxLength(60),
                TextInput::make('name')
                    ->label('名称')
                    ->required()
                    ->maxLength(100),
                FileUpload::make('image_url')
                    ->label('挂件透明图片')
                    ->helperText('支持 PNG、GIF、WebP、AVIF、SVG 等透明图片；不允许 JPG、BMP。可留空，留空时使用内置 CSS 挂件样式。')
                    ->disk('public')
                    ->directory('avatar-frames')
                    ->visibility('public')
                    ->acceptedFileTypes(AvatarFrame::transparentImageMimeTypes())
                    ->maxSize(2048)
                    ->openable()
                    ->downloadable(),
                TextInput::make('css_class')
                    ->label('内置样式')
                    ->helperText('可选：fresh_leaf、sky_badge、starlight_boost。也可留空。')
                    ->maxLength(60),
                TextInput::make('price')
                    ->label('价格（电影票）')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(0),
                Toggle::make('is_free')
                    ->label('免费领取')
                    ->default(false),
                Toggle::make('enabled')
                    ->label('上架')
                    ->default(true),
                Select::make('bonus_type')
                    ->label('加成类型')
                    ->options(AvatarFrame::bonusTypeOptions())
                    ->required()
                    ->native(false)
                    ->default(AvatarFrame::BONUS_NONE),
                TextInput::make('bonus_value')
                    ->label('加成比例')
                    ->helperText('填写小数，例如 0.05 表示 +5%。无加成填 0。')
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                TextInput::make('sort')
                    ->label('排序（越小越靠前）')
                    ->numeric()
                    ->default(0),
                Textarea::make('description')
                    ->label('说明')
                    ->rows(4),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        AvatarFrame::ensureSchema();

        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('id')->sortable(),
                ImageColumn::make('display_image_url')->label('图片')->height(48)->width(48)->circular(),
                TextColumn::make('name')->label('挂件')->searchable(),
                TextColumn::make('code')->label('代码')->searchable(),
                TextColumn::make('price')->label('价格')->formatStateUsing(fn ($state) => number_format((float)$state, 1) . ' 电影票'),
                TextColumn::make('bonus_text')->label('加成')->badge(),
                IconColumn::make('is_free')->label('免费')->boolean(),
                IconColumn::make('enabled')->label('上架')->boolean(),
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
            'index' => ManageAvatarFrames::route('/'),
        ];
    }
}
