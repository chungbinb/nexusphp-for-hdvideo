<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 魔力银行季度分红（P4）：把分红池(贷款利息扣除风险准备金后的部分)按各用户存款占比派发。
 */
class BankDividend extends Command
{
    protected $signature = 'bank:dividend';

    protected $description = 'HDV 魔力银行：按存款占比发放季度分红';

    public function handle()
    {
        require_once base_path('include/bank.php');
        [$total, $count] = bank_distribute_dividends();
        $log = sprintf('[bank:dividend] distributed=%.2f users=%d', $total, $count);
        $this->info($log);
        if (function_exists('do_log')) {
            do_log($log);
        }
        return 0;
    }
}
