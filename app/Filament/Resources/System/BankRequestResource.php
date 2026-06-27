<?php

namespace App\Filament\Resources\System;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\Action;
use App\Filament\Resources\System\BankRequestResource\Pages\ManageBankRequests;
use App\Models\BankRequest;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class BankRequestResource extends Resource
{
    protected static ?string $model = BankRequest::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string | \UnitEnum | null $navigationGroup = 'System';

    protected static ?int $navigationSort = 23;

    public static function getNavigationLabel(): string
    {
        return '魔力银行申请审核';
    }

    public static function getBreadcrumb(): string
    {
        return '魔力银行申请审核';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = BankRequest::query()->where('status', 'pending')->count();
        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('uid')->label('用户')->formatStateUsing(fn ($state) => (User::find($state)->username ?? ('#' . $state))),
                TextColumn::make('type')->label('类型')->badge()->formatStateUsing(fn ($state) => BankRequest::typeLabels()[$state] ?? $state),
                TextColumn::make('reason')->label('理由')->limit(60)->wrap(),
                TextColumn::make('status')->label('状态')->badge()
                    ->formatStateUsing(fn ($state) => ['pending' => '待审核', 'approved' => '已通过', 'rejected' => '已拒绝'][$state] ?? $state)
                    ->color(fn ($state) => ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'gray'][$state] ?? 'gray'),
                TextColumn::make('admin_note')->label('审核备注')->limit(40)->toggleable(),
                TextColumn::make('created_at')->label('申请时间'),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('通过')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (BankRequest $r) => $r->status === 'pending')
                    ->schema([
                        TextInput::make('periods')->label('债务重组：新期数（3/6/12/18/24，仅重组填）')->numeric(),
                        Textarea::make('note')->label('备注（可选）')->rows(2),
                    ])
                    ->action(function (BankRequest $r, array $data) {
                        require_once base_path('include/bank.php');
                        [$ok, $msg] = bank_handle_request($r->id, true, $data['note'] ?? '', $data['periods'] ?? '');
                        Notification::make()->title($msg)->{$ok ? 'success' : 'danger'}()->send();
                    }),
                Action::make('reject')
                    ->label('拒绝')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (BankRequest $r) => $r->status === 'pending')
                    ->schema([
                        Textarea::make('note')->label('拒绝原因（可选）')->rows(2),
                    ])
                    ->action(function (BankRequest $r, array $data) {
                        require_once base_path('include/bank.php');
                        [$ok, $msg] = bank_handle_request($r->id, false, $data['note'] ?? '', '');
                        Notification::make()->title($msg)->{$ok ? 'success' : 'danger'}()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBankRequests::route('/'),
        ];
    }
}
