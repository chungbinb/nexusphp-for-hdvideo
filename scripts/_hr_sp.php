<?php
namespace NexusPlugin\HitAndRun;

use Filament\Panel;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class HitAndRunServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('hit-and-run')
            ->hasTranslations()
        ;
    }

    public function packageRegistered()
    {
        if (!HitAndRunRepository::checkMainApplicationVersion()) {
            return;
        }
        Panel::configureUsing(function (Panel $panel) {
            $panel->plugin(HitAndRun::make());
        });

    }

}
