<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

final class UserModelPatcher
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * Add a package's traits and interfaces to the application User model.
     *
     * Returns the short names that were added, so the caller can report them.
     *
     * @param  list<string>  $traits  Fully qualified trait names.
     * @param  list<string>  $interfaces  Fully qualified interface names.
     * @return list<string>
     */
    public function patch(array $traits, array $interfaces): array
    {
        $path = $this->basePath.'/app/Models/User.php';

        if (($traits === [] && $interfaces === []) || ! $this->files->exists($path)) {
            return [];
        }

        $contents = $this->files->get($path);
        $original = $contents;
        $added = [];

        foreach ($interfaces as $interface) {
            $short = class_basename($interface);

            if ($this->implementsInterface($contents, $short)) {
                continue;
            }

            $patched = $this->addInterface($contents, $short);

            if ($patched === null) {
                continue;
            }

            $contents = $this->addImport($patched, $interface);
            $added[] = $short;
        }

        foreach ($traits as $trait) {
            $short = class_basename($trait);

            if ($this->usesTrait($contents, $short)) {
                continue;
            }

            $patched = $this->addTrait($contents, $short);

            if ($patched === null) {
                continue;
            }

            $contents = $this->addImport($patched, $trait);
            $added[] = $short;
        }

        if ($contents !== $original) {
            $this->files->put($path, $contents);
        }

        return $added;
    }

    /**
     * Determine whether the model already uses a trait.
     */
    private function usesTrait(string $contents, string $short): bool
    {
        return preg_match('/^\h*use\h[^;]*\b'.preg_quote($short, '/').'\b/m', $contents) === 1;
    }

    /**
     * Determine whether the model already implements an interface.
     */
    private function implementsInterface(string $contents, string $short): bool
    {
        return preg_match('/\bimplements\b[^\{]*\b'.preg_quote($short, '/').'\b/', $contents) === 1;
    }

    /**
     * Insert a trait immediately after the class opening brace.
     */
    private function addTrait(string $contents, string $short): ?string
    {
        $patched = preg_replace(
            '/(\n(?:final\h+)?class\h+\w+[^\{]*\{\n)/',
            '$1    use '.$short.";\n",
            $contents,
            1,
            $count,
        );

        return is_string($patched) && $count === 1 ? $patched : null;
    }

    /**
     * Add an interface to the class declaration, creating the clause if needed.
     */
    private function addInterface(string $contents, string $short): ?string
    {
        $patched = preg_replace(
            '/(\n(?:final\h+)?class\h+\w+\h+extends\h+[\w\\\\]+)(\h+implements\h+[^\{]*?)?(\s*\{)/',
            '$1$2, '.$short.'$3',
            $contents,
            1,
            $count,
        );

        if (is_string($patched) && $count === 1) {
            return str_replace(', '.$short, $this->clause($contents, $short), $patched);
        }

        return null;
    }

    /**
     * Build the implements clause fragment for the current declaration.
     */
    private function clause(string $contents, string $short): string
    {
        return preg_match('/\n(?:final\h+)?class\h+\w+\h+extends\h+[\w\\\\]+\h+implements\h/', $contents) === 1
            ? ', '.$short
            : ' implements '.$short;
    }

    /**
     * Add a use statement, keeping the existing import block intact.
     */
    private function addImport(string $contents, string $class): string
    {
        if (str_contains($contents, 'use '.$class.';')) {
            return $contents;
        }

        $patched = preg_replace(
            '/^use /m',
            'use '.$class.";\nuse ",
            $contents,
            1,
        );

        return is_string($patched) ? $patched : $contents;
    }
}
