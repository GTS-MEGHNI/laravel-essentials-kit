<?php

declare(strict_types=1);

use GtsMeghni\EssentialsKit\Installers\DockerStack;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->appPath = sys_get_temp_dir().'/essentials-kit-docker-'.bin2hex(random_bytes(6));

    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/bootstrap');
    $files->ensureDirectoryExists($this->appPath.'/routes');
    $files->put($this->appPath.'/composer.json', json_encode(['name' => 'acme/api'], JSON_PRETTY_PRINT));
    $files->put($this->appPath.'/artisan', '<?php');
    $files->put($this->appPath.'/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");

    $this->app->setBasePath($this->appPath);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->appPath);
});

function deployFile(string $path, string $target): string
{
    return (new Filesystem)->get($path.'/'.$target);
}

it('writes every deployment file', function (): void {
    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])->assertSuccessful();

    $files = new Filesystem;

    foreach (DockerStack::FILES as $target) {
        expect($files->exists($this->appPath.'/'.$target))->toBeTrue($target.' was not written');
    }
});

it('writes nothing without the docker option', function (): void {
    $this->artisan('essentials:install', ['--no-interaction' => true])->assertSuccessful();

    $files = new Filesystem;

    expect($files->exists($this->appPath.'/docker'))->toBeFalse()
        ->and($files->exists($this->appPath.'/.dockerignore'))->toBeFalse();
});

it('writes the entrypoints as executable files', function (): void {
    Process::fake();

    // With reverb, so its entrypoint is among the files written.
    $this->artisan('essentials:install', [
        '--packages' => ['reverb'],
        '--docker' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    foreach (DockerStack::EXECUTABLE as $target) {
        expect(is_executable($this->appPath.'/'.$target))->toBeTrue($target.' is not executable');
    }
})->skipOnWindows();

it('keeps an existing deployment file until the overwrite is forced', function (): void {
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/docker/production/php');
    $files->put($this->appPath.'/docker/production/php/php-fpm.conf', '; tuned for this host');

    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])->assertSuccessful();

    expect(deployFile($this->appPath, 'docker/production/php/php-fpm.conf'))->toBe('; tuned for this host');

    $this->artisan('essentials:install', [
        '--docker' => true,
        '--force' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(deployFile($this->appPath, 'docker/production/php/php-fpm.conf'))->toContain('pm.max_children');
});

it('probes the generated health route rather than the framework one', function (): void {
    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])->assertSuccessful();

    $compose = deployFile($this->appPath, 'docker/production/docker-compose.yml');
    $nginx = deployFile($this->appPath, 'docker/production/nginx/default.conf');

    // The installer removes the framework's health: '/up' route, so a probe
    // pointed at /up would answer 404 for the life of the deployment.
    expect($compose)
        ->toContain('http://127.0.0.1/api/health')
        ->not->toContain('/up')
        ->and($nginx)
        ->toContain('location = /api/health')
        ->not->toContain('location = /up');
});

it('sizes the php-fpm pool within the app container memory limit', function (): void {
    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])->assertSuccessful();

    $pool = deployFile($this->appPath, 'docker/production/php/php-fpm.conf');
    $compose = deployFile($this->appPath, 'docker/production/docker-compose.yml');

    expect(preg_match('/^pm\.max_children\s*=\s*(\d+)/m', $pool, $children))->toBe(1)
        ->and(preg_match('/APP_MEM_LIMIT:-(\d+)m/', $compose, $limit))->toBe(1);

    // Raising max_children past this turns a slow request into an OOM kill:
    // roughly 48MB per child plus opcache and the master process.
    expect((int) $children[1] * 48 + 128)->toBeLessThanOrEqual((int) $limit[1]);
});

it('keeps the timeout ladder ordered so nginx answers before php-fpm kills the worker', function (): void {
    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])->assertSuccessful();

    $nginx = deployFile($this->appPath, 'docker/production/nginx/default.conf');
    $php = deployFile($this->appPath, 'docker/production/php/php.ini');
    $pool = deployFile($this->appPath, 'docker/production/php/php-fpm.conf');
    $host = deployFile($this->appPath, 'docker/production/vps-nginx/site.conf.example');

    expect($nginx)->toContain('fastcgi_read_timeout    60s')
        ->and($php)->toContain('max_execution_time     = 60')
        ->and($host)->toContain('proxy_read_timeout    65s')
        ->and($pool)->toContain('request_terminate_timeout = 75s');
});

it('publishes the container port on loopback only', function (): void {
    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])->assertSuccessful();

    // Docker's DNAT rules are traversed before UFW's filter rules, so this
    // binding, not the firewall, is what keeps the stack off the internet.
    expect(deployFile($this->appPath, 'docker/production/docker-compose.yml'))
        ->toContain('"${HOST_IP:-127.0.0.1}:${HOST_PORT:-3000}:80"');
});

it('keeps local state out of the build context without hiding the deployment files', function (): void {
    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])->assertSuccessful();

    $ignore = deployFile($this->appPath, '.dockerignore');

    $rules = array_values(array_filter(
        array_map('trim', explode("\n", $ignore)),
        static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    ));

    expect($rules)
        ->toContain('.env')
        // A bare pattern matches the context root only, so this is what keeps a
        // deploy env file written next to the compose file out of the image.
        ->toContain('**/.env')
        ->toContain('**/.env.*')
        ->toContain('vendor')
        ->toContain('storage')
        // The Dockerfile copies php/ and entrypoint/ out of docker/production, so
        // no rule may exclude it from the build context.
        ->each(fn ($rule) => $rule->not->toContain('docker/production'));
});

it('warns when the application does not trust the reverse proxy', function (): void {
    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])
        ->expectsOutputToContain('trustProxies')
        ->assertSuccessful();
});

it('does not warn about proxies once the application trusts them', function (): void {
    (new Filesystem)->put($this->appPath.'/bootstrap/app.php', <<<'PHP'
<?php

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
    })->create();

PHP);

    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])
        ->doesntExpectOutputToContain('trustProxies')
        ->assertSuccessful();
});

it('warns when the route the healthchecks probe is missing', function (): void {
    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])
        ->expectsOutputToContain('/api/health')
        ->assertSuccessful();
});

it('does not warn about the health route once it is generated', function (): void {
    $this->artisan('essentials:install', [
        '--features' => ['api', 'health'],
        '--docker' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(deployFile($this->appPath, 'routes/api.php'))->toContain("->name('health')");
});

it('leaves reverb out of a stack that does not broadcast', function (): void {
    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])->assertSuccessful();

    $files = new Filesystem;

    foreach (DockerStack::REALTIME_FILES as $target) {
        expect($files->exists($this->appPath.'/'.$target))->toBeFalse();
    }

    expect(deployFile($this->appPath, 'docker/production/docker-compose.yml'))
        ->not->toContain('reverb')
        ->toContain('QUEUE_MEM_LIMIT:-768m')
        ->and(deployFile($this->appPath, 'docker/production/nginx/default.conf'))->not->toContain('reverb')
        ->and(deployFile($this->appPath, 'docker/production/vps-nginx/site.conf.example'))->not->toContain('(app|apps)')
        ->and(deployFile($this->appPath, 'docker/production/Dockerfile'))->not->toContain('reverb');
});

it('adds the reverb container when reverb is selected in the same run', function (): void {
    Process::fake();

    $this->artisan('essentials:install', [
        '--packages' => ['reverb'],
        '--docker' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $compose = deployFile($this->appPath, 'docker/production/docker-compose.yml');

    expect($compose)
        ->toContain('_reverb')
        ->toContain('/usr/local/bin/reverb-entrypoint')
        ->toContain('http://127.0.0.1:8080/up')
        // The 256m reverb cap comes out of the queue's share.
        ->toContain('REVERB_MEM_LIMIT:-256m')
        ->toContain('QUEUE_MEM_LIMIT:-512m')
        ->not->toContain('QUEUE_MEM_LIMIT:-768m')
        ->and(deployFile($this->appPath, 'docker/production/Dockerfile'))
        ->toContain('reverb-entrypoint.sh')
        ->and(deployFile($this->appPath, 'docker/production/entrypoint/reverb-entrypoint.sh'))
        ->toContain('reverb:start --host=0.0.0.0 --port=8080');
});

it('adds the reverb container when an earlier run required reverb', function (): void {
    (new Filesystem)->put($this->appPath.'/composer.json', json_encode([
        'name' => 'acme/api',
        'require' => ['laravel/reverb' => '^1.0'],
    ], JSON_PRETTY_PRINT));

    $this->artisan('essentials:install', ['--docker' => true, '--no-interaction' => true])->assertSuccessful();

    expect((new Filesystem)->exists($this->appPath.'/docker/production/entrypoint/reverb-entrypoint.sh'))->toBeTrue();
});

it('proxies websocket upgrades to reverb through both nginx layers', function (): void {
    Process::fake();

    $this->artisan('essentials:install', [
        '--packages' => ['reverb'],
        '--docker' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    $nginx = deployFile($this->appPath, 'docker/production/nginx/default.conf');
    $host = deployFile($this->appPath, 'docker/production/vps-nginx/site.conf.example');

    foreach ([$nginx, $host] as $config) {
        expect($config)
            ->toContain('location ~ ^/(app|apps)/ {')
            ->toContain('proxy_set_header Upgrade           $http_upgrade;')
            ->toContain('proxy_set_header Connection        "upgrade";');
    }

    // Regex locations match in file order, so the reverb block has to come
    // before the static-asset regex or a path ending in .js would miss it.
    expect($nginx)->toContain('proxy_pass http://reverb:8080;')
        ->and(strpos($nginx, '(app|apps)'))->toBeLessThan(strpos($nginx, '\.(js|css'));
});

it('never leaks a section marker into a deployment file', function (bool $realtime): void {
    Process::fake();

    $this->artisan('essentials:install', [
        '--packages' => $realtime ? ['reverb'] : [],
        '--docker' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    foreach ([...DockerStack::FILES, ...DockerStack::REALTIME_FILES] as $target) {
        $path = $this->appPath.'/'.$target;

        if (! (new Filesystem)->exists($path)) {
            continue;
        }

        expect((new Filesystem)->get($path))
            ->not->toContain('@realtime')
            ->not->toContain('@unless-realtime')
            ->not->toMatch('/@end\b/');
    }
})->with(['without reverb' => false, 'with reverb' => true]);
