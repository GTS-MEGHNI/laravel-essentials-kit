<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

final class Tooling
{
    /**
     * Development dependencies installed with the tooling group.
     *
     * @var list<string>
     */
    public const array PACKAGES = [
        'laravel/pint',
        'larastan/larastan',
        'phpstan/extension-installer',
        'pestphp/pest',
        'pestphp/pest-plugin-laravel',
        'pestphp/pest-plugin-type-coverage',
        'laravel/boost',
    ];

    /**
     * Composer plugins the tooling needs allow-listed before it can install.
     *
     * A fresh Laravel skeleton does not allow these, and Composer aborts the
     * whole install when it meets a blocked plugin.
     *
     * @var list<string>
     */
    public const array PLUGINS = [
        'pestphp/pest-plugin',
        'phpstan/extension-installer',
    ];

    /**
     * Artisan commands run once the tooling dependencies are installed.
     *
     * @var list<string>
     */
    public const array SETUP = [
        'boost:install',
    ];

    /**
     * Configuration files written into the application root.
     *
     * @var array<string, string>
     */
    public const array FILES = [
        'tooling/pint.json.stub' => 'pint.json',
        'tooling/phpstan.neon.stub' => 'phpstan.neon',
    ];

    /**
     * Composer scripts added to the application so the gates are one command each.
     *
     * @var array<string, list<string>>
     */
    public const array SCRIPTS = [
        'lint' => ['vendor/bin/pint --parallel'],
        'lint:check' => ['vendor/bin/pint --parallel --test'],
        'analyse' => ['vendor/bin/phpstan analyse'],
        'test:types' => ['vendor/bin/pest --type-coverage --min=100'],
        'test:unit' => ['vendor/bin/pest --parallel'],
        'test' => ['@analyse', '@lint:check', '@test:types', '@test:unit'],
    ];
}
