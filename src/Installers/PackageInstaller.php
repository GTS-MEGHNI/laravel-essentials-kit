<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

final class PackageInstaller
{
    /**
     * Seconds allowed for a single Composer or Artisan invocation.
     */
    private const int TIMEOUT = 900;

    /**
     * @param  (Closure(string, string): void)|null  $onOutput  Receives output as the process produces it.
     */
    public function __construct(
        private readonly string $basePath,
        private readonly ?Closure $onOutput = null,
    ) {}

    /**
     * Require the given Composer packages in one invocation.
     *
     * @param  list<string>  $packages
     */
    public function require(array $packages, bool $dev = false): ProcessResult
    {
        return $this->run(sprintf(
            'composer require %s%s --no-interaction',
            $dev ? '--dev ' : '',
            implode(' ', $packages),
        ));
    }

    /**
     * Allow a Composer plugin to run, which Composer otherwise blocks.
     */
    public function allowPlugin(string $plugin): ProcessResult
    {
        return $this->run('composer config --no-plugins allow-plugins.'.$plugin.' true');
    }

    /**
     * Run an Artisan command in a fresh process so newly installed code is loaded.
     */
    public function artisan(string $command): ProcessResult
    {
        return $this->run('php artisan '.$command.' --no-interaction');
    }

    /**
     * Run a command in the application root, streaming its output.
     */
    private function run(string $command): ProcessResult
    {
        return Process::path($this->basePath)
            ->timeout(self::TIMEOUT)
            ->run($command, $this->onOutput);
    }
}
