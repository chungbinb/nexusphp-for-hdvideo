<?php

namespace App\Filament\Pages;

use App\Filament\OptionsTrait;
use App\Models\Setting;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class TorrentRegionStyleSetting extends Page implements HasForms
{
    use InteractsWithForms, OptionsTrait;

    protected string $view = 'filament.pages.torrent-region-style-setting';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1001;

    protected static string $routePath = 'torrent-region-style-setting';

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('admin.sidebar.torrent_region_style_setting');
    }

    public function getTitle(): string|Htmlable
    {
        return self::getNavigationLabel();
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Setting::class);
    }

    public function mount(): void
    {
        $settings = Setting::getFromDb('torrent_region_style', []);
        foreach ($this->getDefaultSettings() as $key => $value) {
            if (Arr::get($settings, $key) === null) {
                Arr::set($settings, $key, $value);
            }
        }
        $this->content->fill(['torrent_region_style' => $settings]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Radio::make('torrent_region_style.enabled')
                    ->options(self::$yesOrNo)
                    ->inline(true)
                    ->label(__('label.enabled'))
                    ->helperText(__('label.setting.torrent_region_style.enabled_help')),
                Radio::make('torrent_region_style.required')
                    ->options(self::$yesOrNo)
                    ->inline(true)
                    ->label(__('label.setting.torrent_region_style.required'))
                    ->helperText(__('label.setting.torrent_region_style.required_help')),
                Textarea::make('torrent_region_style.regions')
                    ->rows(8)
                    ->label(__('label.setting.torrent_region_style.regions'))
                    ->helperText(__('label.setting.torrent_region_style.regions_help')),
                Textarea::make('torrent_region_style.styles')
                    ->rows(8)
                    ->label(__('label.setting.torrent_region_style.styles'))
                    ->helperText(__('label.setting.torrent_region_style.styles_help')),
            ])
            ->columns(2)
            ->statePath('data');
    }

    public function submit(): void
    {
        $state = $this->content->getState();
        $settings = Arr::get($state, 'torrent_region_style', []);
        $data = [];
        foreach ($this->getDefaultSettings() as $key => $default) {
            $value = Arr::get($settings, $key, $default);
            $data[] = [
                'name' => "torrent_region_style.$key",
                'value' => is_array($value) ? json_encode($value) : $value,
                'autoload' => 'yes',
            ];
        }

        Setting::query()->upsert($data, ['name'], ['value', 'autoload']);
        clear_setting_cache();
        send_admin_success_notification();
    }

    private function getDefaultSettings(): array
    {
        return [
            'enabled' => 'yes',
            'required' => 'yes',
            'regions' => "中国大陆\n美国\n韩国\n英国\n泰国\n中国港台\n日本\n法国\n德国\n意大利",
            'styles' => "短片\n喜剧\n动作\n科幻\n惊悚\n剧情\n爱情\n恐怖\n犯罪\n悬疑",
        ];
    }
}
