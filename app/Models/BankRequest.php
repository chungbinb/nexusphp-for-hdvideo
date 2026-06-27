<?php

namespace App\Models;

class BankRequest extends NexusModel
{
    public $timestamps = false;

    protected $table = 'hdvideo_bank_requests';

    protected $guarded = [];

    public static function typeLabels(): array
    {
        return [
            'insurance_claim' => '保险理赔',
            'bankruptcy' => '破产保护',
            'restructure' => '债务重组',
        ];
    }
}
