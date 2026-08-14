<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit;

use GtsMeghni\EssentialsKit\Console\InstallCommand;
use Illuminate\Support\ServiceProvider;

class EssentialsKitServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            InstallCommand::class,
        ]);
    }
}
