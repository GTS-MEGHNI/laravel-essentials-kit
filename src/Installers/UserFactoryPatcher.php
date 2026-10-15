<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

/**
 * Make the skeleton UserFactory seed Algerian names instead of Faker's defaults.
 */
final class UserFactoryPatcher
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * Rewrite the name attribute of the user factory.
     *
     * Returns null when the factory was patched or already uses Algerian names,
     * and an explanation when the developer has to do it by hand.
     */
    public function patch(): ?string
    {
        $path = $this->basePath.'/database/factories/UserFactory.php';

        if (! $this->files->exists($path)) {
            return 'database/factories/UserFactory.php was not found.';
        }

        $contents = $this->files->get($path);

        if (str_contains($contents, 'fake()->algerianName(')) {
            return null;
        }

        $patched = preg_replace(
            "/('name'\h*=>\h*)fake\(\)->name\(\)/",
            '$1fake()->algerianName()',
            $contents,
            1,
            $count,
        );

        if (! is_string($patched) || $count !== 1) {
            return 'The name attribute in database/factories/UserFactory.php was not in the expected shape.';
        }

        $this->files->put($path, $patched);

        return null;
    }
}
