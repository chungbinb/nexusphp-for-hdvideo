<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Nexus\Database\NexusDB;

/**
 * 魔力银行自动还款（P2）：每日把有借款用户的钱包电影票优先用于偿还借款。
 */
class BankAutoRepay extends Command
{
    protected $signature = 'bank:auto_repay {--uid=}';

    protected $description = 'HDV 魔力银行：自动用钱包电影票偿还借款（每日）';

    public function handle()
    {
        require_once base_path('include/bank.php');
        bank_ensure_tables();

        $uidOpt = $this->option('uid');
        $where = "`loan` > 0";
        if (is_numeric($uidOpt)) {
            $where .= " AND `uid` = " . (int) $uidOpt;
        }
        $res = sql_query("SELECT `uid` FROM `" . BANK_ACCOUNT_TABLE . "` WHERE $where");
        $uids = [];
        if ($res) {
            while ($row = mysql_fetch_assoc($res)) {
                $uids[] = (int) $row['uid'];
            }
        }

        $total = 0.0;
        $count = 0;
        foreach ($uids as $uid) {
            $paid = bank_auto_repay($uid);
            if ($paid > 0) {
                $total += $paid;
                $count++;
            }
            // 触发逾期/黑名单状态更新
            bank_restricted($uid);
        }

        $log = sprintf('[bank:auto_repay] users=%d repaid_users=%d total=%.2f', count($uids), $count, $total);
        $this->info($log);
        if (function_exists('do_log')) {
            do_log($log);
        }
        return 0;
    }
}
