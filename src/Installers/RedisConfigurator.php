<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

final class RedisConfigurator
{
    /**
     * The client backed by the PECL extension.
     */
    public const string PHPREDIS = 'phpredis';

    /**
     * The client backed by a pure PHP Composer package.
     */
    public const string PREDIS = 'predis';

    /**
     * The Composer package the predis client needs.
     */
    public const string PREDIS_PACKAGE = 'predis/predis';

    /**
     * The answer that configures nothing.
     */
    public const string NONE = 'none';

    /**
     * The connection defaults a stripped environment file may be missing.
     *
     * @var array<string, string>
     */
    private const array CONNECTION = [
        'REDIS_HOST' => '127.0.0.1',
        'REDIS_PASSWORD' => 'null',
        'REDIS_PORT' => '6379',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
    ) {}

    /**
     * Determine whether the phpredis extension is loaded by the current PHP.
     *
     * This is the machine running the installer, which says nothing about the
     * machine the application deploys to, so it only picks the default answer.
     */
    public static function extensionLoaded(): bool
    {
        return extension_loaded('redis');
    }

    /**
     * Point the environment files at the chosen client.
     *
     * The connection defaults are appended rather than set, so a host that
     * already points at a real Redis server keeps its own values.
     *
     * @return list<string> The keys that were written.
     */
    public function configure(string $client, bool $cache): array
    {
        $keys = ['REDIS_CLIENT' => $client];

        if ($cache) {
            $keys['CACHE_STORE'] = 'redis';
        }

        $set = (new EnvSetter($this->files, $this->basePath))->set($keys);
        $appended = (new EnvAppender($this->files, $this->basePath))->append(self::CONNECTION);

        return array_values(array_unique([...$set, ...$appended]));
    }
}
