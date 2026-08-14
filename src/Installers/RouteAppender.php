<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

final class RouteAppender
{
    /**
     * Classes the appended routes need to import.
     *
     * @var list<string>
     */
    private const array IMPORTS = [
        'App\Support\ApiResponse',
        'Illuminate\Http\JsonResponse',
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Route',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
        private readonly string $stubPath,
    ) {}

    /**
     * Append a route stub to the application API routes file.
     *
     * Returns null when the routes were appended or were already present, and
     * an explanation when the developer has to add them by hand.
     */
    public function append(string $stub, string $marker): ?string
    {
        $path = $this->basePath.'/routes/api.php';

        if (! $this->files->exists($path)) {
            return 'routes/api.php was not found. Run php artisan install:api first.';
        }

        $contents = $this->files->get($path);

        if (str_contains($contents, $marker)) {
            return null;
        }

        $routes = $this->files->get($this->stubPath.'/routes/'.$stub);

        $this->files->put($path, $this->addImports(rtrim($contents)."\n").rtrim($routes)."\n");

        return null;
    }

    /**
     * Add the use statements the appended routes depend on.
     *
     * The whole import block is rewritten in alphabetical order, because Pint
     * orders imports case insensitively and would otherwise flag the result.
     */
    private function addImports(string $contents): string
    {
        $block = '/^use [^;]+;(?:\nuse [^;]+;)*\n/m';

        if (preg_match($block, $contents, $matches) !== 1) {
            // After the strict types declaration when there is one, because it
            // has to stay the very first statement in the file or PHP fatals.
            $anchor = preg_match('/^declare\([^)]*\);\n/m', $contents) === 1
                ? '/^(declare\([^)]*\);\n)/m'
                : '/^(<\?php\n)/';

            return preg_replace(
                $anchor,
                "$1\n".$this->imports(self::IMPORTS),
                $contents,
                1,
            ) ?? $contents;
        }

        $existing = array_map(
            static fn (string $line): string => trim(substr(trim($line), 4), ';'),
            explode("\n", trim($matches[0])),
        );

        return preg_replace(
            $block,
            str_replace('$', '\$', $this->imports(array_merge($existing, self::IMPORTS))),
            $contents,
            1,
        ) ?? $contents;
    }

    /**
     * Render a sorted, deduplicated import block.
     *
     * @param  list<string>  $imports
     */
    private function imports(array $imports): string
    {
        $imports = array_values(array_unique($imports));

        usort($imports, strcasecmp(...));

        return implode('', array_map(
            static fn (string $import): string => 'use '.$import.";\n",
            $imports,
        ));
    }
}
