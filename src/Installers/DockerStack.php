<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

use Illuminate\Filesystem\Filesystem;

final class DockerStack
{
    /**
     * Stub path mapped to its application destination.
     *
     * The Dockerfile copies the php and entrypoint files from
     * docker/production/, so these paths are part of the build and not just a
     * convention.
     *
     * @var array<string, string>
     */
    public const array FILES = [
        'docker/Dockerfile.stub' => 'docker/production/Dockerfile',
        'docker/docker-compose.yml.stub' => 'docker/production/docker-compose.yml',
        'docker/README.md.stub' => 'docker/production/README.md',
        'docker/entrypoint/app-entrypoint.sh.stub' => 'docker/production/entrypoint/app-entrypoint.sh',
        'docker/entrypoint/queue-entrypoint.sh.stub' => 'docker/production/entrypoint/queue-entrypoint.sh',
        'docker/entrypoint/scheduler-entrypoint.sh.stub' => 'docker/production/entrypoint/scheduler-entrypoint.sh',
        'docker/php/php.ini.stub' => 'docker/production/php/php.ini',
        'docker/php/opcache.ini.stub' => 'docker/production/php/opcache.ini',
        'docker/php/php-fpm.conf.stub' => 'docker/production/php/php-fpm.conf',
        'docker/nginx/default.conf.stub' => 'docker/production/nginx/default.conf',
        'docker/vps-nginx/site.conf.example.stub' => 'docker/production/vps-nginx/site.conf.example',
        'docker/vps-nginx/maintenance.html.stub' => 'docker/production/vps-nginx/maintenance.html',
        'docker/dockerignore.stub' => '.dockerignore',
    ];

    /**
     * Destinations that have to keep their executable bit.
     *
     * The Dockerfile chmods them again inside the image, but the entrypoints are
     * also the fastest way to reproduce a boot failure locally, and a stub copied
     * with Filesystem::put arrives at 0644.
     *
     * @var list<string>
     */
    public const array EXECUTABLE = [
        'docker/production/entrypoint/app-entrypoint.sh',
        'docker/production/entrypoint/queue-entrypoint.sh',
        'docker/production/entrypoint/scheduler-entrypoint.sh',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $basePath,
        private readonly string $stubPath,
    ) {}

    /**
     * Write the deployment files into the application.
     *
     * An existing file is kept unless the caller forces the overwrite: a deploy
     * that is already tuned to its host must not be replaced by the defaults on
     * a second install run.
     *
     * @return array{created: list<string>, kept: list<string>}
     */
    public function write(bool $force): array
    {
        $created = [];
        $kept = [];

        foreach (self::FILES as $stub => $target) {
            $path = $this->basePath.'/'.$target;

            if ($this->files->exists($path) && ! $force) {
                $kept[] = $target;

                continue;
            }

            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, $this->files->get($this->stubPath.'/'.$stub));

            if (in_array($target, self::EXECUTABLE, true)) {
                $this->files->chmod($path, 0755);
            }

            $created[] = $target;
        }

        return ['created' => $created, 'kept' => $kept];
    }

    /**
     * Determine whether the application trusts the reverse proxy.
     *
     * Both nginx layers send X-Forwarded-For, -Proto, -Host and -Port, and
     * Laravel ignores every one of them until the app trusts the proxy. Untrusted
     * headers fail quietly: http:// links, 403 on valid signed URLs, one rate
     * limit bucket for all traffic, and the proxy IP in every log line.
     */
    public function trustsProxies(): bool
    {
        $path = $this->basePath.'/bootstrap/app.php';

        return $this->files->exists($path)
            && str_contains($this->files->get($path), 'trustProxies');
    }

    /**
     * Determine whether the route the container healthchecks probe exists.
     *
     * The compose healthchecks and the container nginx both point at
     * /api/health, because the installer removes the framework's health: '/up'
     * route. Without the generated route every probe answers 404 forever.
     */
    public function hasHealthRoute(): bool
    {
        $path = $this->basePath.'/routes/api.php';

        return $this->files->exists($path)
            && str_contains($this->files->get($path), "->name('health')");
    }
}
