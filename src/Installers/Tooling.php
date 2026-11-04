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
     * Git hooks written into .githooks, executable.
     *
     * pre-commit stays fast, Pint on the staged files and PHPStan, so it does
     * not get skipped by habit. pre-push runs composer audit and the full gate.
     *
     * @var array<string, string>
     */
    public const array HOOKS = [
        'tooling/githooks/pre-commit.stub' => '.githooks/pre-commit',
        'tooling/githooks/pre-push.stub' => '.githooks/pre-push',
    ];

    /**
     * Point git at .githooks, only inside a checkout.
     *
     * file_exists rather than is_dir, because .git is a file in a worktree. A
     * Docker build or a deploy without .git skips it instead of failing.
     */
    public const string HOOKS_COMMAND = "@php -r \"if (file_exists('.git')) { passthru('git config core.hooksPath .githooks'); }\"";

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
        'hooks' => [self::HOOKS_COMMAND],
    ];
}
