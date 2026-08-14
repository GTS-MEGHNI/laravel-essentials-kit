<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

/**
 * Keep a development-only Telescope installation from breaking production.
 *
 * `telescope:install` registers App\Providers\TelescopeServiceProvider in
 * bootstrap/providers.php unconditionally. That class extends a parent shipped
 * by the package, so a production deploy running `composer install --no-dev`
 * fatals on every request before it reaches the application. Telescope
 * documents the fix as registering the providers conditionally instead.
 */
final class TelescopeGuard
{
    /**
     * The provider `telescope:install` publishes into the application.
     */
    private const string PROVIDER = 'App\Providers\TelescopeServiceProvider';

    /**
     * The package provider, used as the marker proving the guard is in place.
     */
    private const string PACKAGE_PROVIDER = 'Laravel\Telescope\TelescopeServiceProvider';

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * Move Telescope's registration behind an environment check.
     *
     * @return list<string> The files that were changed.
     */
    public function guard(): array
    {
        return array_values(array_filter([
            $this->unregister(),
            $this->registerConditionally(),
        ]));
    }

    /**
     * Drop the published provider from the application manifest.
     */
    private function unregister(): ?string
    {
        $path = $this->basePath.'/bootstrap/providers.php';

        if (! $this->files->exists($path)) {
            return null;
        }

        $contents = $this->files->get($path);
        $updated = preg_replace(
            '/^\h*\\\\?'.preg_quote(self::PROVIDER, '/').'::class,\n/m',
            '',
            $contents,
            1,
        );

        if (! is_string($updated) || $updated === $contents) {
            return null;
        }

        $this->files->put($path, $updated);

        return 'bootstrap/providers.php';
    }

    /**
     * Register both providers from AppServiceProvider, outside production.
     */
    private function registerConditionally(): ?string
    {
        $path = $this->basePath.'/app/Providers/AppServiceProvider.php';

        if (! $this->files->exists($path)) {
            return null;
        }

        $contents = $this->files->get($path);

        if (str_contains($contents, self::PACKAGE_PROVIDER)) {
            return null;
        }

        // class_exists() rather than the environment alone, because a
        // production database seeded with a local .env would otherwise still
        // reach for a class Composer never installed.
        $body = <<<'PHP'
                if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
                    $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
                    $this->app->register(TelescopeServiceProvider::class);
                }
        PHP;

        $updated = preg_replace(
            '/(public function register\(\): void\n\h*\{\n)(\h*\/\/\n)?/',
            '$1'.$body."\n",
            $contents,
            1,
        );

        if (! is_string($updated) || $updated === $contents) {
            return null;
        }

        $this->files->put($path, $updated);

        return 'app/Providers/AppServiceProvider.php';
    }
}
