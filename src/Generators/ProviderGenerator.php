<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Generators;

use Illuminate\Filesystem\Filesystem;

final class ProviderGenerator
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly string $stubPath,
    ) {}

    /**
     * Render the application service provider for the selected features.
     *
     * @param  list<Feature>  $features
     */
    public function render(array $features): string
    {
        $features = self::contributing($features);

        $rendered = str_replace(
            ['{{ imports }}', '{{ register }}', '{{ boot }}', '{{ methods }}'],
            [
                $this->imports($features),
                $this->register($features),
                $this->boot($features),
                $this->methods($features),
            ],
            $this->files->get($this->stubPath.'/provider.stub'),
        );

        return (string) preg_replace("/\n{3,}/", "\n\n", $rendered);
    }

    /**
     * Keep only the features that contribute a boot method.
     *
     * @param  list<Feature>  $features
     * @return list<Feature>
     */
    public static function booting(array $features): array
    {
        return array_values(array_filter(
            $features,
            static fn (Feature $feature): bool => $feature->bootsProvider,
        ));
    }

    /**
     * Keep only the features that contribute a register method.
     *
     * @param  list<Feature>  $features
     * @return list<Feature>
     */
    public static function registering(array $features): array
    {
        return array_values(array_filter(
            $features,
            static fn (Feature $feature): bool => $feature->registersProvider,
        ));
    }

    /**
     * Keep only the features that put anything in the generated provider.
     *
     * @param  list<Feature>  $features
     * @return list<Feature>
     */
    public static function contributing(array $features): array
    {
        return array_values(array_filter(
            $features,
            static fn (Feature $feature): bool => $feature->bootsProvider || $feature->registersProvider,
        ));
    }

    /**
     * Build the sorted, de-duplicated use statement block.
     *
     * @param  list<Feature>  $features
     */
    private function imports(array $features): string
    {
        $imports = ['Illuminate\Support\ServiceProvider'];

        foreach ($features as $feature) {
            $imports = [...$imports, ...$feature->imports];
        }

        $imports = array_values(array_unique($imports));
        usort($imports, static fn (string $left, string $right): int => strcasecmp($left, $right));

        return implode("\n", array_map(
            static fn (string $import): string => 'use '.$import.';',
            $imports,
        ));
    }

    /**
     * Build the generated register method, or nothing when no feature binds.
     *
     * @param  list<Feature>  $features
     */
    private function register(array $features): string
    {
        return $this->entryPoint(
            self::registering($features),
            'register',
            'Register the container bindings installed by Laravel Essentials Kit.',
            static fn (Feature $feature): string => $feature->registerMethod(),
        );
    }

    /**
     * Build the generated boot method, or nothing when no feature boots.
     *
     * @param  list<Feature>  $features
     */
    private function boot(array $features): string
    {
        return $this->entryPoint(
            self::booting($features),
            'boot',
            'Bootstrap the application defaults installed by Laravel Essentials Kit.',
            static fn (Feature $feature): string => $feature->method(),
        );
    }

    /**
     * Build one public provider method calling into the features that fill it.
     *
     * Returns nothing when no feature does, so a provider built only from
     * container bindings carries no empty boot method, and the other way round.
     *
     * @param  list<Feature>  $features
     * @param  callable(Feature): string  $call
     */
    private function entryPoint(array $features, string $name, string $summary, callable $call): string
    {
        if ($features === []) {
            return '';
        }

        $calls = implode("\n", array_map(
            static fn (Feature $feature): string => '        $this->'.$call($feature).'();',
            $features,
        ));

        return "\n".'    /**'."\n"
            .'     * '.$summary."\n"
            .'     */'."\n"
            .'    public function '.$name.'(): void'."\n"
            .'    {'."\n".$calls."\n".'    }'."\n";
    }

    /**
     * Build the generated private boot methods.
     *
     * @param  list<Feature>  $features
     */
    private function methods(array $features): string
    {
        if ($features === []) {
            return '';
        }

        $methods = [
            ...array_map(
                fn (Feature $feature): string => $this->method('register', $feature),
                self::registering($features),
            ),
            ...array_map(
                fn (Feature $feature): string => $this->method('boot', $feature),
                self::booting($features),
            ),
        ];

        return "\n\n".implode("\n\n", $methods);
    }

    /**
     * Read one generated method out of its stub directory.
     */
    private function method(string $kind, Feature $feature): string
    {
        return rtrim($this->files->get($this->stubPath.'/'.$kind.'/'.$feature->stub()));
    }
}
