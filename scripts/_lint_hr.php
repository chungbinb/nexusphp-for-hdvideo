<?php
namespace NexusPlugin\HitAndRun;

use App\Filament\OptionsTrait;
use App\Models\BonusLogs;
use App\Models\HitAndRun;
use App\Models\Message;
use App\Models\NexusModel;
use App\Models\SearchBox;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserMeta;
use App\Repositories\BonusRepository;
use App\Repositories\UserRepository;
use Carbon\Carbon;
use Elasticsearch\Endpoints\Search;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Nexus\Database\NexusDB;
use Nexus\Nexus;
use Nexus\Plugin\BasePlugin;
use NexusPlugin\HitAndRun\Filament\HitAndRunResource;
use NexusPlugin\HitAndRun\Filament\WinningRecordResource;
use NexusPlugin\HitAndRun\Models\HitAndRunPrize;
use Filament\Forms;
use NexusPlugin\HitAndRun\Models\HitAndRunWinningRecord;

class HitAndRunRepository extends BasePlugin
{
    use OptionsTrait;

    const ID = "hit_and_run";

    const CACHE_KEY = 'plugin_hit_and_run';

    const COMPATIBLE_NP_VERSION = '1.9.0';

    const VERSION = '3.0.0';

    public function install()
    {
        $this->runMigrations($this->getMigrationFilePath());
        $this->initSectionHitAndRunSetting(SearchBox::SECTION_BROWSE . "_");
        $this->initSectionHitAndRunSetting(SearchBox::SECTION_SPECIAL . "_");
        clear_setting_cache();
    }

    public function uninstall()
    {
        $this->runMigrations($this->getMigrationFilePath(), true);
    }

    public function boot()
    {
        $self = new self;
        $basePath = dirname(__DIR__);
        Nexus::addTranslationNamespace($basePath . '/resources/lang', 'hit-and-run');

//        add_action('nexus_register', [$self, 'actionRegisterFilamentResource'], 10, 1);

        add_filter('hit_and_run_setting_schema', [$self, 'filterAddSettingSchema'], 10, 1);
        add_filter('nexus_setting_get', [$self, 'filterGetSetting'], 10, 3);
        add_filter("hit_and_run_diff_in_section", [$self, 'diffInSection'], 10, 1);

        add_action('nexus_setting_update', [$self, 'actionSyncToNoPrefix']);
    }

    private function getMigrationFilePath(): string
    {
        return dirname(__DIR__) . '/database/migrations';
    }

    public function diffInSection($value): bool
    {
        return true;
    }

    public function getIsEnabled(): bool
    {
        return true;
    }

    public function actionSyncToNoPrefix()
    {
        $sql = sprintf(
            "insert into settings (`name`, `value`, `autoload`) select replace(`name`, '%s_', ''), `value`, `autoload` from settings where `name` like '%s_hr.%s' on duplicate key update `value` = values(`value`), updated_at = now()",
            SearchBox::SECTION_BROWSE, SearchBox::SECTION_BROWSE, '%'
        );
        $result = NexusDB::statement($sql);
        do_log("sql: $sql, result: " . (is_object($result) ? get_class($result) : var_export($result, true)));
    }

    public function initSectionHitAndRunSetting($namePrefix)
    {
        $sql = sprintf(
            "insert into settings (`name`, `value`, `autoload`) select CONCAT_WS('', '%s', `name`), `value`, `autoload` from settings where `name` like 'hr.%s' on duplicate key update `value` = values(`value`), updated_at = now()",
            $namePrefix, '%'
        );
        $result = NexusDB::statement($sql);
        do_log("sql: $sql, result: " . (is_object($result) ? get_class($result) : var_export($result, true)));
    }

    public function filterGetSetting($default, $name, array $options)
    {
        if (empty($options['mode'])) {
            return $default;
        }
        $mode = (string)$options['mode'];
        $newName = match ($mode) {
            Setting::get('main.browsecat') => SearchBox::SECTION_BROWSE . "_hr.$name",
            Setting::get('main.specialcat') => SearchBox::SECTION_SPECIAL. "_hr.$name",
            default => throw new \InvalidArgumentException("Invalid mode: $mode")
        };
        if (str_ends_with($newName, '.*')) {
            $newName = strstr($newName, '.', true);
        }
        return Setting::get($newName);
    }


    public function actionRegisterFilamentResource()
    {
        Filament::registerResources([HitAndRunResource::class]);
        Livewire::component(get_filament_class_alias(HitAndRunResource\Pages\ManageHitAndRun::class), HitAndRunResource\Pages\ManageHitAndRun::class);

        Filament::registerResources([WinningRecordResource::class]);
        Livewire::component(get_filament_class_alias(WinningRecordResource\Pages\ManageWinningRecords::class), WinningRecordResource\Pages\ManageWinningRecords::class);
    }

    public function store(array $data)
    {
        $data = $this->formatData($data);
        return NexusDB::transaction(function () use ($data) {
            $prize = HitAndRunPrize::query()->create($data);
            $this->updateSumProbability();
            return $prize;
        });
    }

    public function update(array $data, NexusModel $prize)
    {
        $data = $this->formatData($data);
        return NexusDB::transaction(function () use ($data, $prize) {
            $prize->update($data);
            $this->updateSumProbability();
            return $prize;
        });
    }

    public function delete(NexusModel $record)
    {
        return NexusDB::transaction(function () use ($record) {
            $record->delete();
            $this->updateSumProbability();
            return true;
        });
    }

    private function formatData(array $data): array
    {
        if (in_array($data['type'], HitAndRunPrize::$constantAmountTypes)) {
            $data['amount'] = 1;
        }
        return array_filter($data);
    }

    private function updateSumProbability()
    {
        $sumProbability = HitAndRunPrize::query()->sum('probability');
        NexusDB::cache_put($this->getSumProbabilityCacheKey(), $sumProbability,86400 * 365 * 100);
    }

    public function getSumProbability()
    {
        return  NexusDB::cache_get($this->getSumProbabilityCacheKey());
    }

    public function getProbabilityReal($probability): string
    {
        $sum = $this->getSumProbability();
        if ($sum <= 0) {
            return '-';
        }
        return number_format($probability / $this->getSumProbability(), 4);
    }

    private function getSumProbabilityCacheKey(): string
    {
        return self::CACHE_KEY . ":sumProbability";
    }

    public function listPrizes($withRealProbability = true): \Illuminate\Database\Eloquent\Collection|array
    {
        $prizes = HitAndRunPrize::query()->orderBy('priority', 'desc')->get();
        if ($withRealProbability) {
            foreach ($prizes as &$prize) {
                $prize->probability_real = $this->getProbabilityReal($prize->probability);
            }
        }
        return $prizes;
    }

    public function winPrize()
    {
        $user = Auth::user();
        $lockKey = self::CACHE_KEY . ":running:" . $user->id;
        if (NexusDB::cache_get($lockKey)) {
            throw new \RuntimeException(__('hit-and-run::hit-and-run.click_duplicate'));
        }
        $requireBonus = Setting::get('lucky_draw.require_bonus');
        if ($user->seedbonus < $requireBonus) {
            throw new \RuntimeException(__('hit-and-run::hit-and-run.bonus_not_enough'));
        }
        $prizes = $this->listPrizes(false);
        $sum = $this->getSumProbability();
        if ($sum <= 0) {
            throw new \RuntimeException(__('hit-and-run::hit-and-run.config_error'));
        }
        $winPrize = $prizeIndex = null;
        foreach ($prizes as $index => $prize) {
            $probability = $prize->probability;
            $random = mt_rand(1, $sum);
            if ($random <= $probability) {
                $winPrize = $prize;
                $prizeIndex = $index;
                break;
            } else {
                $sum -= $probability;
            }
        }
        if (!$winPrize) {
            throw new \RuntimeException("Something wrong!");
        }
        $result = $this->storeWinningRecord($user, $requireBonus, $prizeIndex, $prizes->count(), $winPrize);
        $duration = mt_rand(3000, 8000);//这是毫秒，缓存单位为秒
        NexusDB::cache_put($lockKey, $winPrize->id, floor($duration / 1000));
        $result['duration'] = $duration;
        return $result;
    }

    private function storeWinningRecord(User $user, $requireBonus, $index, $total, HitAndRunPrize $prize)
    {
        do_log("[STORE_WINNING_RECORD], user: {$user->id}, require_bonus: $requireBonus, index: $index, total: $total ,prize: " . nexus_json_encode($prize->toArray()));
        $insert = [
            'uid' => $user->id,
            'cost_bonus' => $requireBonus,
            'prize_id' => $prize->id,
            'prize_type' => $prize->type,
            'prize_info' => $prize
        ];
        $winningRecord = NexusDB::transaction(function () use ($user, $insert, $prize, $requireBonus) {
            $bonusRep = new BonusRepository();
            $logComment = __('hit-and-run::hit-and-run.consume_bonus_comment', ['bonus' => $requireBonus, 'name' => __('hit-and-run::hit-and-run.label')]);
            //先消耗用户魔力
            $bonusRep->consumeUserBonus($user, $insert['cost_bonus'], BonusLogs::BUSINESS_TYPE_LUCKY_DRAW, $logComment);
            //再发放权益
            $this->issuanceBenefits($user, $prize);
            //最后创建中奖记录
            return HitAndRunWinningRecord::query()->create($insert);
        });
        $circleNum = mt_rand(5, 10);
        $angleBase = $circleNum * 360;
        $angleStep = 360 / $total;
        $start = -$angleStep / 2;
        $angleOffsetMin = ceil($angleStep * $index);
        $angleOffsetMax = floor($angleOffsetMin + $angleStep);
        $angleOffset = mt_rand($angleOffsetMin, $angleOffsetMax);
        return [
            'angle' => 360 - ($angleOffset + $start) + $angleBase,
            'prize_text' => sprintf('%s %s', $prize->typeText, $prize->amountText),
            'easing' => $this->getAnimation(),
            'winning_record_id' => $winningRecord->id,
        ];
    }

    private function getAnimation(): string
    {
        $inOut = ['In', 'Out', 'InOut'];
        $types = ['Quad', 'Cubic', 'Quart', 'Quint', 'Sine', 'Expo', 'Circ', 'Elastic', 'Back', 'Bounce'];
        if (mt_rand(1, 100) == 1) {
            return 'jswing';
        }
        return sprintf('ease%s%s', $inOut[mt_rand(0, count($inOut) - 1)], $types[mt_rand(0, count($types) - 1)]);
    }

    public function filterAddSettingSchema(array $schema): array
    {
        $tabs = [];
        foreach (SearchBox::listSections('text') as $key => $value) {
            if ($key == SearchBox::SECTION_BROWSE || ($key == SearchBox::SECTION_SPECIAL && Setting::get('main.spsct') == 'yes')) {
                $tabs[] = Tab::make($value)->id($key)->schema([
                    Forms\Components\Radio::make("{$key}_".'hr.mode')->options(HitAndRun::listModes(true))->inline(true)->label(__('label.setting.hr.mode')),
                    Forms\Components\TextInput::make("{$key}_".'hr.inspect_time')->helperText(__('label.setting.hr.inspect_time_help'))->label(__('label.setting.hr.inspect_time'))->integer(),
                    Forms\Components\TextInput::make("{$key}_".'hr.seed_time_minimum')->helperText(__('label.setting.hr.seed_time_minimum_help'))->label(__('label.setting.hr.seed_time_minimum'))->integer(),
                    Forms\Components\TextInput::make("{$key}_".'hr.ignore_when_ratio_reach')->helperText(__('label.setting.hr.ignore_when_ratio_reach_help'))->label(__('label.setting.hr.ignore_when_ratio_reach'))->integer(),
                    Forms\Components\TextInput::make("{$key}_".'hr.ban_user_when_counts_reach')->helperText(__('label.setting.hr.ban_user_when_counts_reach_help'))->label(__('label.setting.hr.ban_user_when_counts_reach'))->integer(),
                    Forms\Components\TextInput::make("{$key}_".'hr.include_rate')->helperText(__('label.setting.hr.include_rate_help'))->label(__('label.setting.hr.include_rate'))->numeric(),
                ])->columns(2);
            }
        }
        $tabSection = Tabs::make('')->tabs($tabs)->columnSpan(['sm' => 2]);
        return [$tabSection];
    }

    private function issuanceBenefits(User $user, HitAndRunPrize $prize)
    {
        do_log("[ISSUANCE_BENEFITS], user: {$user->id}, prize: {$prize->id}");
        NexusDB::transaction(function () use ($user, $prize) {
            match ($prize->type) {
                HitAndRunPrize::TYPE_THANKS => $this->issuanceBenefitThanks($user, $prize),

                HitAndRunPrize::TYPE_PERSONALIZED_USERNAME => $this->issuanceBenefitPersonalizedUsername($user, $prize),
                HitAndRunPrize::TYPE_CHANGE_USERNAME => $this->issuanceBenefitAddUserMeta($user, ['meta_key' => UserMeta::META_KEY_CHANGE_USERNAME, ], ['updated_at' => NexusDB::raw('now()')]),

                HitAndRunPrize::TYPE_BONUS => $this->issuanceBenefitBonus($user, $prize),
                HitAndRunPrize::TYPE_UPLOADED => $this->issuanceBenefitIncreaseUserField($user, $prize, 'uploaded', $prize->amount * 1024 * 1024 * 1024),
                HitAndRunPrize::TYPE_ATTENDANCE_CARD => $this->issuanceBenefitIncreaseUserField($user, $prize, 'attendance_card', $prize->amount),
                HitAndRunPrize::TYPE_INVITE => $this->issuanceBenefitIncreaseUserField($user, $prize, 'invites', $prize->amount),

                HitAndRunPrize::TYPE_VIP => $this->issuanceBenefitVIP($user, $prize),

                default => throw new \RuntimeException("Invalid prize type: " . $prize->type)

            };
            if ($prize->type != HitAndRunPrize::TYPE_THANKS) {
                $this->insertWinPrizeMessage($user, $prize);
            }
        });

    }

    private function issuanceBenefitThanks(User $user, HitAndRunPrize $prize)
    {
        do_log("Thanks");
    }

    private function issuanceBenefitPersonalizedUsername(User $user, HitAndRunPrize $prize)
    {
        $metaKey = UserMeta::META_KEY_PERSONALIZED_USERNAME;
        $days = $prize->amount;
        $meta = $user->metas()->where('meta_key', $metaKey)->first();
        if (!$meta) {
            //No record, just insert one
            $insert = [
                'meta_key' => $metaKey,
                'deadline' => now()->addDays($days),
            ];
            $user->metas()->create($insert);
        } else {
            if ($meta->deadline) {
                //Not permanently, update deadline
                if ($meta->deadline->lte(now())) {
                    $deadline = now()->addDays((int)$days);
                } else {
                    $deadline = $meta->deadline->addDays((int)$days);
                }
                $meta->deadline = $deadline;
            }
            $meta->status = UserMeta::STATUS_NORMAL;
            $meta->save();
        }
        clear_user_cache($user->id);
    }

    private function issuanceBenefitAddUserMeta(User $user, array $metaData, array $keyExistsUpdates = [])
    {
        $userRep = new UserRepository();
        $userRep->addMeta($user, $metaData, $keyExistsUpdates);
    }

    private function issuanceBenefitIncreaseUserField(User $user, HitAndRunPrize $prize, $field, $value)
    {
        $user->update([$field => NexusDB::raw("$field + $value")]);
        clear_user_cache($user->id, $user->passkey);
    }

    private function issuanceBenefitBonus(User $user, HitAndRunPrize $prize)
    {
        $comment = __('hit-and-run::hit-and-run.issuance_benefit_body.' . HitAndRunPrize::TYPE_BONUS, [
            'name' => __('hit-and-run::hit-and-run.label'),
            'type_text' => $prize->typeText,
            'amount_text' => $prize->amountText
        ]);
        $user->updateWithComment([
            'seedbonus' => NexusDB::raw("seedbonus + " . $prize->amount),
        ], date('Y-m-d') . " $comment", "bonuscomment");
    }

    private function issuanceBenefitVIP(User $user, HitAndRunPrize $prize)
    {
        if ($user->class >= User::CLASS_VIP) {
            do_log("already VIP or above.");
            $rewardBonus = Setting::get('lucky_draw.vip_nullify_reward_bonus');
            $comment = __('hit-and-run::hit-and-run.issuance_benefit_body_vip_nullify', ['reward_bonus' => $rewardBonus]);
            $user->updateWithComment([
                'seedbonus' => NexusDB::raw("seedbonus + " . $rewardBonus),
            ], date('Y-m-d') . " $comment", "bonuscomment");
        } else {
            $update = [
                'class' => User::CLASS_VIP,
                'vip_added' => 'yes',
                'vip_until' => now()->addDays((int)$prize->amount)
            ];
            $user->update($update);
            clear_user_cache($user->id, $user->passkey);
        }
    }

    private function insertWinPrizeMessage(User $user, HitAndRunPrize $prize)
    {
        if ($user->class >= User::CLASS_VIP && $prize->type == HitAndRunPrize::TYPE_VIP) {
            $rewardBonus = Setting::get('lucky_draw.vip_nullify_reward_bonus');
            $body = __('hit-and-run::hit-and-run.issuance_benefit_body_vip_nullify', ['reward_bonus' => $rewardBonus]);
        } else {
            $body = __('hit-and-run::hit-and-run.issuance_benefit_body.' . $prize->type, [
                'name' => __('hit-and-run::hit-and-run.label'),
                'type_text' => $prize->typeText,
                'amount_text' => $prize->amountText
            ]);
        }
        $msg = [
            'receiver' => $user->id,
            'added' => now(),
            'subject' => __('hit-and-run::hit-and-run.issuance_benefit_subject', ['name' => __('hit-and-run::hit-and-run.label')]),
            'msg' => $body,
        ];
        Message::add($msg);
    }

    public function filterRenderOnHomePage(array $modules): array
    {
        if (!$this->getIsEnabled()) {
            return $modules;
        }
        $records = HitAndRunWinningRecord::query()
            ->where('prize_type', '!=', HitAndRunPrize::TYPE_THANKS)
            ->orderBy('id', 'desc')
            ->take(15)
            ->get();
        $html = sprintf('<h2>%s<font class="small"> - [<a class="altlink" href="/plugin/hit-and-run" target="_blank"><b>%s</b></a>]</font></h2>', nexus_trans('hit-and-run::hit-and-run.label'), nexus_trans('hit-and-run::hit-and-run.home_display.go'));
        $html .= '<table width="100%" border="0" cellpadding="5">';
        $html .= '<tr><td width="100%">';
        foreach ($records as $record) {
            $trContent = nexus_trans('hit-and-run::hit-and-run.home_display.tr_content', [
                'time' => format_datetime($record->created_at),
                'username' => get_username($record->uid),
                'type_text' => nexus_trans('hit-and-run::hit-and-run.type.' . $record->prize_info->type),
                'amount_text' => $record->prize_info->amountText,
            ]);
            $html .= "<div>$trContent</div>";
        }
        $html .= '</td></tr>';
        $html .= "</table>";
        $modules[] = $html;
        return $modules;
    }


}
