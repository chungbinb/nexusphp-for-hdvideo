<?php
namespace NexusPlugin\HitAndRun;
use App\Support\StaticMake;
use Filament\Contracts\Plugin;
use Filament\Panel;

class HitAndRun implements Plugin
{
    use StaticMake;

    const ID = "hit_and_run";

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {

    }

    public function boot(Panel $panel): void
    {

    }

}
