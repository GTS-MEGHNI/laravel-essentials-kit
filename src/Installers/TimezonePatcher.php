<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

/**
 * Point the application timezone at a real zone instead of UTC.
 *
 * The Laravel skeleton hardcodes `'timezone' => 'UTC'` in config/app.php, so
 * setting APP_TIMEZONE in the environment alone does nothing. This rewrites the
 * value to read from the environment, with the requested zone as the default, so
 * a deployment can still override it per environment.
 */
final class TimezonePatcher
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * Rewrite the configured timezone.
     *
     * Returns null when the file was patched or already reads the environment,
     * and an explanation when the developer has to do it by hand.
     */
    public function patch(string $timezone): ?string
    {
        $path = $this->basePath.'/config/app.php';

        if (! $this->files->exists($path)) {
            return 'config/app.php was not found.';
        }

        $contents = $this->files->get($path);

        if (preg_match("/'timezone'\h*=>\h*env\(/", $contents) === 1) {
            return null;
        }

        $patched = preg_replace(
            "/('timezone'\h*=>\h*)'[^']*'/",
            '$1'."env('APP_TIMEZONE', '".$timezone."')",
            $contents,
            1,
            $count,
        );

        if (! is_string($patched) || $count !== 1) {
            return 'The timezone entry in config/app.php was not in the expected shape.';
        }

        $this->files->put($path, $patched);

        return null;
    }
}
