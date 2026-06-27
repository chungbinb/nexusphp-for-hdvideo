<?php

namespace App\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BankSetting extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_bank_settings';

    protected $fillable = ['transfer_enabled'];

    protected $casts = [
        'transfer_enabled' => 'boolean',
    ];

    public static function ensureSchema(): void
    {
        $conn = (new static)->getConnectionName();
        $schema = Schema::connection($conn);
        if (! $schema->hasTable('hdvideo_bank_settings')) {
            $schema->create('hdvideo_bank_settings', function (Blueprint $table) {
                $table->integer('id')->unsigned()->primary();
                $table->boolean('transfer_enabled')->default(true);
                $table->dateTime('updated_at')->nullable();
            });
        }
        if (! DB::connection($conn)->table('hdvideo_bank_settings')->where('id', 1)->exists()) {
            DB::connection($conn)->table('hdvideo_bank_settings')->insert([
                'id' => 1, 'transfer_enabled' => 1, 'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
        }
    }
}
