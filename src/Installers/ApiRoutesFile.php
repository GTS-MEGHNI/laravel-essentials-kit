<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

/**
 * Create routes/api.php and register it, without installing Sanctum.
 *
 * The framework only creates this file through `install:api`, which also
 * requires laravel/sanctum through Composer and prompts to run migrations.
 * Features like the health endpoint and the route groups need the file but not
 * Sanctum, and they are generated before Composer runs at all, so the kit
 * creates it here instead.
 */
final class ApiRoutesFile
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
        private readonly string $stubPath,
    ) {}

    /**
     * Make sure routes/api.php exists and is routed to.
     *
     * @return array{created: bool, failure: string|null}
     */
    public function ensure(): array
    {
        $path = $this->basePath.'/routes/api.php';
        $created = false;

        if (! $this->files->exists($path)) {
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $this->files->get($this->stubPath.'/routes/api.php.stub'));

            $created = true;
        }

        return ['created' => $created, 'failure' => $this->register()];
    }

    /**
     * Add the api argument to withRouting() in bootstrap/app.php.
     *
     * Returns null when the file is routed, and an explanation otherwise. This
     * mirrors what the framework's own install:api command does, including the
     * commented out argument the skeleton has shipped since Laravel 11.
     */
    private function register(): ?string
    {
        $path = $this->basePath.'/bootstrap/app.php';

        if (! $this->files->exists($path)) {
            return 'bootstrap/app.php was not found.';
        }

        $contents = $this->files->get($path);

        if (preg_match('/^\h*api:/m', $contents) === 1) {
            return null;
        }

        $patterns = [
            '/^(\h*)\/\/ api: /m' => '$1api: ',
            '/^(\h*)(web: __DIR__\.\'\/\.\.\/routes\/web\.php\',\n)/m' => '$1$2$1'."api: __DIR__.'/../routes/api.php',\n",
            '/^(\h*)(commands: __DIR__\.\'\/\.\.\/routes\/console\.php\',\n)/m' => '$1'."api: __DIR__.'/../routes/api.php',\n".'$1$2',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $patched = preg_replace($pattern, $replacement, $contents, 1, $count);

            if (is_string($patched) && $count === 1) {
                $this->files->put($path, $patched);

                return null;
            }
        }

        return 'The withRouting() call in bootstrap/app.php was not in the expected shape.';
    }
}
