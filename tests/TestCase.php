<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Tests;

use GtsMeghni\EssentialsKit\EssentialsKitServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            EssentialsKitServiceProvider::class,
        ];
    }
}
