<?php

namespace App\Models;

class GameHallControl extends NexusModel
{
    public $timestamps = false;

    protected $table = 'game_hall_controls';

    protected $fillable = ['game_key', 'name', 'is_open', 'min_class', 'sort'];

    protected $casts = [
        'is_open' => 'boolean',
        'min_class' => 'integer',
        'sort' => 'integer',
    ];
}
