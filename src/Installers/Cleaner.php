<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

final class Cleaner
{
    /**
     * Paths a JSON API project does not need, mapped to why they are removable.
     *
     * @var array<string, string>
     */
    public const array PATHS = [
        'resources/views' => 'Blade templates',
        'resources/css' => 'Frontend styles',
        'resources/js' => 'Frontend scripts',
        'routes/web.php' => 'Browser routes',
        'config/view.php' => 'Blade configuration',
        'config/session.php' => 'Session configuration',
        'vite.config.js' => 'Frontend build configuration',
        'vite.config.ts' => 'Frontend build configuration',
        'package.json' => 'Node dependencies',
        'package-lock.json' => 'Node lock file',
        'pnpm-lock.yaml' => 'Node lock file',
        'yarn.lock' => 'Node lock file',
        'bun.lockb' => 'Node lock file',
        'postcss.config.js' => 'PostCSS configuration',
        'tailwind.config.js' => 'Tailwind configuration',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * The removable paths that actually exist in this application.
     *
     * @return array<string, string>
     */
    public function candidates(): array
    {
        return array_filter(
            self::PATHS,
            fn (string $reason, string $path): bool => $this->files->exists($this->basePath.'/'.$path),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Determine whether the working tree has uncommitted changes.
     *
     * A dirty tree means a deletion cannot be undone with git, so the caller
     * should refuse to delete. A directory that is not a git repository is
     * reported as dirty for the same reason.
     */
    public function isDirty(): bool
    {
        if (! $this->files->isDirectory($this->basePath.'/.git')) {
            return true;
        }

        $status = Process::path($this->basePath)->run('git status --porcelain');

        return ! $status->successful() || trim($status->output()) !== '';
    }

    /**
     * Delete the given application relative paths.
     *
     * @param  list<string>  $paths
     */
    public function delete(array $paths): void
    {
        foreach ($paths as $path) {
            $absolute = $this->basePath.'/'.$path;

            if ($this->files->isDirectory($absolute)) {
                $this->files->deleteDirectory($absolute);

                continue;
            }

            $this->files->delete($absolute);
        }
    }

    /**
     * Remove the web route registration from the application bootstrap file.
     *
     * Returns false when the file is missing or the registration was not found,
     * so the caller can tell the developer to remove it by hand.
     */
    public function removeWebRouting(): bool
    {
        return $this->removeRouting('/^\s*web:\s*[^,\n]+,\n/m');
    }

    /**
     * Remove the framework health route from the application bootstrap file.
     *
     * The `health: '/up'` argument registers a second health endpoint that
     * answers outside the API response envelope, so it is only worth removing
     * once the generated /api/health route has replaced it.
     */
    public function removeHealthRouting(): bool
    {
        return $this->removeRouting('/^\s*health:\s*[^,\n]+,\n/m');
    }

    /**
     * Drop a single withRouting() argument from the bootstrap file.
     */
    private function removeRouting(string $pattern): bool
    {
        $path = $this->basePath.'/bootstrap/app.php';

        if (! $this->files->exists($path)) {
            return false;
        }

        $contents = $this->files->get($path);

        $updated = preg_replace($pattern, '', $contents, 1);

        if (! is_string($updated) || $updated === $contents) {
            return false;
        }

        $this->files->put($path, $updated);

        return true;
    }
}
