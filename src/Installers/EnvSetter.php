<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

final class EnvSetter
{
    /**
     * The environment files a key belongs in.
     *
     * @var list<string>
     */
    private const array FILES = ['.env', '.env.example'];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * Give every key the requested value in every environment file that exists.
     *
     * Unlike EnvAppender, this rewrites keys that are already declared. It is
     * only for keys the developer has just answered a question about, never for
     * keys that could hold a credential the application already set.
     *
     * @param  array<string, string>  $keys  Key mapped to the value it should hold.
     * @return list<string> The keys whose value changed.
     */
    public function set(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $changed = [];

        foreach (self::FILES as $file) {
            $path = $this->basePath.'/'.$file;

            if (! $this->files->exists($path)) {
                continue;
            }

            $contents = $this->files->get($path);
            $updated = $contents;

            foreach ($keys as $key => $value) {
                $result = $this->apply($updated, $key, $value);

                if ($result === $updated) {
                    continue;
                }

                $updated = $result;
                $changed[] = $key;
            }

            if ($updated === $contents) {
                continue;
            }

            $this->files->put($path, $updated);
        }

        return array_values(array_unique($changed));
    }

    /**
     * Rewrite a declared key, or append it when the file does not declare one.
     *
     * A commented out declaration counts as declared, so the value replaces it
     * in place rather than leaving the file with the key written twice.
     */
    private function apply(string $contents, string $key, string $value): string
    {
        $pattern = '/^\h*#?\h*'.preg_quote($key, '/').'\h*=.*$/m';
        $line = $key.'='.$value;

        if (preg_match($pattern, $contents) === 1) {
            $updated = preg_replace($pattern, $line, $contents, 1);

            return is_string($updated) ? $updated : $contents;
        }

        return rtrim($contents)."\n\n".$line."\n";
    }
}
