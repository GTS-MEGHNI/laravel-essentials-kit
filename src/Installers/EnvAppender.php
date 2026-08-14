<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

final class EnvAppender
{
    /**
     * The environment files a new key belongs in.
     *
     * @var list<string>
     */
    private const array FILES = ['.env', '.env.example'];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * Append the keys a package needs to every environment file that exists.
     *
     * Keys already present are left at whatever value the application gave
     * them, so re-running never overwrites real credentials.
     *
     * @param  array<string, string>  $keys  Key mapped to its default value.
     * @return list<string> The keys that were added.
     */
    public function append(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $added = [];

        foreach (self::FILES as $file) {
            $path = $this->basePath.'/'.$file;

            if (! $this->files->exists($path)) {
                continue;
            }

            $contents = $this->files->get($path);
            $missing = array_filter(
                $keys,
                fn (string $value, string $key): bool => ! $this->has($contents, $key),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($missing === []) {
                continue;
            }

            $block = '';

            foreach ($missing as $key => $value) {
                $block .= $key.'='.$value."\n";
            }

            $this->files->put($path, rtrim($contents)."\n\n".$block);

            $added = [...$added, ...array_keys($missing)];
        }

        return array_values(array_unique($added));
    }

    /**
     * Determine whether a key is already declared, commented out or not.
     */
    private function has(string $contents, string $key): bool
    {
        return preg_match('/^\h*#?\h*'.preg_quote($key, '/').'\h*=/m', $contents) === 1;
    }
}
