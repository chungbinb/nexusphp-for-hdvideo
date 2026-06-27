<?php

namespace App\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BankConfig extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_bank_config';

    protected $fillable = ['deposit_rate', 'fixed_rate', 'loan_rate', 'overdue_fee', 'max_loan', 'min_amount'];

    protected $casts = [
        'deposit_rate' => 'float',
        'fixed_rate' => 'float',
        'loan_rate' => 'float',
        'overdue_fee' => 'integer',
        'max_loan' => 'integer',
        'min_amount' => 'integer',
    ];

    /**
     * Self-managed single-row config table (no migration). Kept in sync with
     * include/bank.php which reads the same table.
     */
    public static function ensureSchema(): void
    {
        $conn = (new static)->getConnectionName();
        $schema = Schema::connection($conn);
        if (! $schema->hasTable('hdvideo_bank_config')) {
            $schema->create('hdvideo_bank_config', function (Blueprint $table) {
                $table->integer('id')->unsigned()->primary();
                $table->decimal('deposit_rate', 8, 4)->default(0.1000);
                $table->decimal('fixed_rate', 8, 4)->default(0.2500);
                $table->decimal('loan_rate', 8, 4)->default(0.3000);
                $table->bigInteger('overdue_fee')->default(500);
                $table->bigInteger('max_loan')->default(100000);
                $table->integer('min_amount')->default(100);
                $table->dateTime('updated_at')->nullable();
            });
        } elseif (! $schema->hasColumn('hdvideo_bank_config', 'fixed_rate')) {
            $schema->table('hdvideo_bank_config', function (Blueprint $table) {
                $table->decimal('fixed_rate', 8, 4)->default(0.2500);
                $table->bigInteger('overdue_fee')->default(500);
            });
        }
        if (! $schema->hasTable('hdvideo_bank_accounts')) {
            $schema->create('hdvideo_bank_accounts', function (Blueprint $table) {
                $table->integer('uid')->unsigned()->primary();
                $table->decimal('deposit', 20, 2)->default(0);
                $table->integer('deposit_ts')->unsigned()->default(0);
                $table->decimal('loan', 20, 2)->default(0);
                $table->integer('loan_ts')->unsigned()->default(0);
                $table->dateTime('updated_at');
            });
        }
        if (! DB::connection($conn)->table('hdvideo_bank_config')->where('id', 1)->exists()) {
            DB::connection($conn)->table('hdvideo_bank_config')->insert([
                'id' => 1, 'deposit_rate' => 0.1, 'fixed_rate' => 0.25, 'loan_rate' => 0.3, 'overdue_fee' => 500,
                'max_loan' => 100000, 'min_amount' => 100, 'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
