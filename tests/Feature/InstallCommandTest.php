<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->appPath = sys_get_temp_dir().'/essentials-kit-'.bin2hex(random_bytes(6));

    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/app/Providers');
    $files->ensureDirectoryExists($this->appPath.'/bootstrap');
    $files->ensureDirectoryExists($this->appPath.'/routes');
    $files->put($this->appPath.'/bootstrap/providers.php', <<<'PHP'
<?php

return [
    App\Providers\AppServiceProvider::class,
];

PHP);

    $files->put($this->appPath.'/composer.json', json_encode(['name' => 'acme/api'], JSON_PRETTY_PRINT));
    $files->put($this->appPath.'/artisan', '<?php');
    $files->ensureDirectoryExists($this->appPath.'/routes');
    $files->put($this->appPath.'/routes/api.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");

    $this->app->setBasePath($this->appPath);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->appPath);
});

function base_path_of_package(): string
{
    return dirname(__DIR__, 2);
}

function generatedProvider(string $path): string
{
    return (new Filesystem)->get($path.'/app/Providers/EssentialsServiceProvider.php');
}

it('generates a provider containing every boot group', function (): void {
    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertSuccessful();

    $contents = generatedProvider($this->appPath);

    expect($contents)
        ->toContain('final class EssentialsServiceProvider extends ServiceProvider')
        ->toContain('$this->bootDatabase();')
        ->toContain('$this->bootModels();')
        ->toContain('$this->bootDates();')
        ->toContain('$this->bootSecurity();')
        ->toContain('$this->bootObservability();')
        ->toContain('DB::prohibitDestructiveCommands($this->app->isProduction());')
        ->toContain('Date::use(CarbonImmutable::class);')
        ->toContain('Model::shouldBeStrict(! $this->app->isProduction());');
});

it('generates only the selected boot groups', function (): void {
    $this->artisan('essentials:install', ['--features' => ['dates'], '--no-interaction' => true])->assertSuccessful();

    $contents = generatedProvider($this->appPath);

    expect($contents)
        ->toContain('$this->bootDates();')
        ->toContain('use Carbon\CarbonImmutable;')
        ->not->toContain('bootDatabase')
        ->not->toContain('use Illuminate\Support\Facades\DB;');
});

it('produces syntactically valid php', function (): void {
    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertSuccessful();

    $path = $this->appPath.'/app/Providers/EssentialsServiceProvider.php';

    exec('php -l '.escapeshellarg($path), $output, $status);

    expect($status)->toBe(0);
})->skipOnWindows();

it('registers the generated provider in the manifest', function (): void {
    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertSuccessful();

    expect((new Filesystem)->get($this->appPath.'/bootstrap/providers.php'))
        ->toContain('App\Providers\EssentialsServiceProvider::class,')
        ->toContain('App\Providers\AppServiceProvider::class,');
});

it('does not register the provider twice', function (): void {
    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertSuccessful();
    $this->artisan('essentials:install', ['--all' => true, '--force' => true, '--no-interaction' => true])->assertSuccessful();

    $manifest = (new Filesystem)->get($this->appPath.'/bootstrap/providers.php');

    expect(substr_count($manifest, 'EssentialsServiceProvider::class'))->toBe(1);
});

it('refuses to overwrite an existing provider without force', function (): void {
    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertSuccessful();

    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertFailed();
});

it('writes nothing when no groups are selected', function (): void {
    $this->artisan('essentials:install', ['--features' => ['unknown-group'], '--no-interaction' => true])->assertSuccessful();

    expect((new Filesystem)->exists($this->appPath.'/app/Providers/EssentialsServiceProvider.php'))->toBeFalse();
});

it('generates output that pint considers formatted', function (): void {
    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertSuccessful();

    $path = $this->appPath.'/app/Providers/EssentialsServiceProvider.php';

    exec(base_path_of_package().'/vendor/bin/pint --test '.escapeshellarg($path).' 2>&1', $output, $status);

    expect($status)->toBe(0);
})->skipOnWindows();

it('writes the api layer files', function (): void {
    $this->artisan('essentials:install', ['--features' => ['api'], '--no-interaction' => true])->assertSuccessful();

    $files = new Filesystem;

    expect($files->exists($this->appPath.'/app/Support/ApiResponse.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Exceptions/ApiExceptionRenderer.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Http/Middleware/ForceJsonResponse.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Http/Middleware/RequestId.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Providers/EssentialsServiceProvider.php'))->toBeFalse();
});

it('wires the api layer into bootstrap/app.php', function (): void {
    $files = new Filesystem;
    $files->put($this->appPath.'/bootstrap/app.php', <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

PHP);

    $this->artisan('essentials:install', ['--features' => ['api'], '--no-interaction' => true])->assertSuccessful();

    $bootstrap = $files->get($this->appPath.'/bootstrap/app.php');

    expect($bootstrap)
        ->toContain('use App\Exceptions\ApiExceptionRenderer;')
        ->toContain('use App\Http\Middleware\ForceJsonResponse;')
        ->toContain('use App\Http\Middleware\RequestId;')
        ->toContain('$middleware->append(ForceJsonResponse::class);')
        ->toContain('$middleware->append(RequestId::class);')
        ->toContain('$exceptions->shouldRenderJsonWhen(static fn (): bool => true);')
        ->toContain('$exceptions->render(new ApiExceptionRenderer);');

    exec('php -l '.escapeshellarg($this->appPath.'/bootstrap/app.php'), $output, $status);

    expect($status)->toBe(0);
})->skipOnWindows();

it('does not wire bootstrap/app.php twice', function (): void {
    $files = new Filesystem;
    $files->put($this->appPath.'/bootstrap/app.php', <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

PHP);

    $this->artisan('essentials:install', ['--features' => ['api'], '--no-interaction' => true])->assertSuccessful();
    $this->artisan('essentials:install', ['--features' => ['api'], '--force' => true, '--no-interaction' => true])->assertSuccessful();

    expect(substr_count($files->get($this->appPath.'/bootstrap/app.php'), 'ApiExceptionRenderer::class'))->toBe(0)
        ->and(substr_count($files->get($this->appPath.'/bootstrap/app.php'), 'new ApiExceptionRenderer'))->toBe(1);
});

it('writes the phone files without generating a provider', function (): void {
    $this->artisan('essentials:install', ['--features' => ['phone'], '--no-interaction' => true])->assertSuccessful();

    $files = new Filesystem;

    expect($files->exists($this->appPath.'/app/Support/PhoneNumber.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Rules/AlgerianPhoneNumber.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Providers/EssentialsServiceProvider.php'))->toBeFalse();
});

it('generates every stub as syntactically valid php', function (): void {
    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertSuccessful();

    $paths = [
        '/app/Providers/EssentialsServiceProvider.php',
        '/app/Support/ApiResponse.php',
        '/app/Support/PhoneNumber.php',
        '/app/Exceptions/ApiExceptionRenderer.php',
        '/app/Http/Middleware/ForceJsonResponse.php',
        '/app/Http/Middleware/RequestId.php',
        '/app/Rules/AlgerianPhoneNumber.php',
        '/app/Support/Otp/OtpGenerator.php',
        '/app/Support/Otp/RandomOtpGenerator.php',
        '/app/Support/Otp/FakeOtpGenerator.php',
        '/app/Support/Otp/OtpStore.php',
        '/config/otp.php',
        '/app/Models/Province.php',
        '/app/Models/Commune.php',
        '/database/migrations/0001_01_01_000010_create_provinces_table.php',
        '/database/migrations/0001_01_01_000011_create_communes_table.php',
        '/database/seeders/AlgeriaGeoSeeder.php',
    ];

    foreach ($paths as $path) {
        exec('php -l '.escapeshellarg($this->appPath.$path), $output, $status);

        expect($status)->toBe(0);
    }
})->skipOnWindows();

it('checks every generated file against pint', function (): void {
    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertSuccessful();

    exec(
        base_path_of_package().'/vendor/bin/pint --test '
            .escapeshellarg($this->appPath.'/app').' '
            .escapeshellarg($this->appPath.'/config').' '
            .escapeshellarg($this->appPath.'/database/migrations').' '
            .escapeshellarg($this->appPath.'/database/seeders').' 2>&1',
        $output,
        $status,
    );

    expect($status)->toBe(0);
})->skipOnWindows();

it('analyses every generated file at the level it installs', function (): void {
    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertSuccessful();

    $files = new Filesystem;

    // The generated phpstan.neon sets level max, so generated code has to pass
    // at level max. This package analyses itself at a lower level, which is why
    // the stubs need their own check rather than riding on the suite's.
    $config = $this->appPath.'/phpstan.generated.neon';
    $files->put($config, <<<NEON
    parameters:
        level: max
        paths:
            - {$this->appPath}/app
        scanDirectories:
            - {$this->appPath}/app
        treatPhpDocTypesAsCertain: false
        tmpDir: {$this->appPath}/build/phpstan
    NEON);

    exec(
        base_path_of_package().'/vendor/bin/phpstan analyse --no-progress --no-ansi -c '
            .escapeshellarg($config).' 2>&1',
        $output,
        $status,
    );

    expect($status)->toBe(0, implode("\n", $output));
})->skipOnWindows();

it('writes the geo files without generating a provider', function (): void {
    $this->artisan('essentials:install', ['--features' => ['geo'], '--no-interaction' => true])->assertSuccessful();

    $files = new Filesystem;

    expect($files->exists($this->appPath.'/database/data/algeria.json'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Models/Province.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Models/Commune.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/database/seeders/AlgeriaGeoSeeder.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/database/migrations/0001_01_01_000010_create_provinces_table.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/database/migrations/0001_01_01_000011_create_communes_table.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Providers/EssentialsServiceProvider.php'))->toBeFalse();
});

it('ships every province and commune translated into arabic, french, and english', function (): void {
    $this->artisan('essentials:install', ['--features' => ['geo'], '--no-interaction' => true])->assertSuccessful();

    $provinces = json_decode(
        (new Filesystem)->get($this->appPath.'/database/data/algeria.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($provinces)->toBeArray()->toHaveCount(69);

    $communes = 0;

    foreach ($provinces as $province) {
        expect($province['code'])->toMatch('/^\d{2}$/')
            ->and($province['name'])->toHaveKeys(['ar', 'fr', 'en']);

        foreach ($province['communes'] as $commune) {
            expect($commune['id'])->toBeInt()
                ->and($commune['name'])->toHaveKeys(['ar', 'fr', 'en']);

            $communes++;
        }
    }

    expect($communes)->toBe(1559);
});

it('requires the selected packages and runs their setup', function (): void {
    Process::fake();

    $this->artisan('essentials:install', [
        '--features' => [],
        '--packages' => ['sanctum', 'telescope'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertRan(fn ($process): bool => str_contains($process->command, 'composer require laravel/sanctum'));
    Process::assertRan(fn ($process): bool => str_contains($process->command, 'composer require --dev laravel/telescope'));
    Process::assertRan(fn ($process): bool => str_contains($process->command, 'vendor:publish --tag=sanctum-migrations'));
    Process::assertDidntRun(fn ($process): bool => str_contains($process->command, 'artisan install:api'));
    Process::assertRan(fn ($process): bool => str_contains($process->command, 'artisan telescope:install'));
});

it('installs no packages when none are selected', function (): void {
    Process::fake();

    $this->artisan('essentials:install', ['--features' => ['dates'], '--no-interaction' => true])->assertSuccessful();

    Process::assertNothingRan();
});

it('skips setup commands when composer fails', function (): void {
    Process::fake([
        'composer*' => Process::result(exitCode: 1),
    ]);

    $this->artisan('essentials:install', [
        '--features' => [],
        '--packages' => ['sanctum'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertDidntRun(fn ($process): bool => str_contains($process->command, 'artisan vendor:publish'));
});

it('installs tooling and writes its configuration', function (): void {
    Process::fake();

    $files = new Filesystem;

    $this->artisan('essentials:install', [
        '--features' => [],
        '--tooling' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->exists($this->appPath.'/pint.json'))->toBeTrue()
        ->and($files->get($this->appPath.'/phpstan.neon'))->toContain('level: max');

    $manifest = json_decode($files->get($this->appPath.'/composer.json'), true);

    expect($manifest['scripts']['test:types'])->toBe(['vendor/bin/pest --type-coverage --min=100'])
        ->and($manifest['name'])->toBe('acme/api');

    Process::assertRan(fn ($process): bool => str_contains($process->command, 'larastan/larastan'));
    Process::assertRan(fn ($process): bool => str_contains($process->command, 'pestphp/pest-plugin-type-coverage'));
    Process::assertRan(fn ($process): bool => str_contains($process->command, 'artisan boost:install'));
});

it('refuses to clean up a dirty working tree', function (): void {
    Process::fake(['git status*' => Process::result(output: ' M app/Foo.php')]);

    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/.git');
    $files->ensureDirectoryExists($this->appPath.'/resources/views');
    $files->put($this->appPath.'/resources/views/welcome.blade.php', 'hi');

    $this->artisan('essentials:install', [
        '--features' => [],
        '--cleanup' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->exists($this->appPath.'/resources/views/welcome.blade.php'))->toBeTrue();
});

it('deletes non api paths from a clean working tree', function (): void {
    Process::fake(['git status*' => Process::result(output: '')]);

    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/.git');
    $files->ensureDirectoryExists($this->appPath.'/resources/views');
    $files->put($this->appPath.'/resources/views/welcome.blade.php', 'hi');
    $files->put($this->appPath.'/routes/web.php', '<?php');
    $files->put($this->appPath.'/bootstrap/app.php', <<<'PHP'
<?php

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
    )->create();

PHP);

    $this->artisan('essentials:install', [
        '--features' => [],
        '--cleanup' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->exists($this->appPath.'/resources/views'))->toBeFalse()
        ->and($files->exists($this->appPath.'/routes/web.php'))->toBeFalse()
        ->and($files->get($this->appPath.'/bootstrap/app.php'))
        ->not->toContain("web: __DIR__.'/../routes/web.php'")
        ->toContain("api: __DIR__.'/../routes/api.php'");
});

it('refuses to install packages without a composer manifest', function (): void {
    Process::fake();

    (new Filesystem)->delete($this->appPath.'/composer.json');

    $this->artisan('essentials:install', [
        '--features' => [],
        '--packages' => ['sanctum'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertNothingRan();
});

it('refuses to run composer inside a vendor directory', function (): void {
    Process::fake();

    $files = new Filesystem;
    $vendorPath = $this->appPath.'/vendor/acme/skeleton';
    $files->ensureDirectoryExists($vendorPath);
    $files->put($vendorPath.'/composer.json', '{}');
    $files->put($vendorPath.'/artisan', '<?php');

    $this->app->setBasePath($vendorPath);

    $this->artisan('essentials:install', [
        '--features' => [],
        '--packages' => ['sanctum'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertNothingRan();
});

it('refuses to run composer without an artisan file', function (): void {
    Process::fake();

    (new Filesystem)->delete($this->appPath.'/artisan');

    $this->artisan('essentials:install', [
        '--features' => [],
        '--packages' => ['sanctum'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertNothingRan();
});

it('allows the blocked composer plugins before installing tooling', function (): void {
    Process::fake();

    $this->artisan('essentials:install', [
        '--features' => [],
        '--tooling' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertRan(fn ($process): bool => str_contains(
        $process->command,
        'composer config --no-plugins allow-plugins.pestphp/pest-plugin true',
    ));
    Process::assertRan(fn ($process): bool => str_contains(
        $process->command,
        'composer config --no-plugins allow-plugins.phpstan/extension-installer true',
    ));
});

it('appends the health route to the api routes file', function (): void {
    $this->artisan('essentials:install', [
        '--features' => ['api', 'health'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    $routes = (new Filesystem)->get($this->appPath.'/routes/api.php');

    expect($routes)
        ->toContain("use App\Support\ApiResponse;")
        ->toContain("use Illuminate\Http\JsonResponse;")
        ->not->toContain('use Throwable;')
        ->toContain("Route::get('health'")
        ->toContain("->name('health');");

    exec('php -l '.escapeshellarg($this->appPath.'/routes/api.php'), $output, $status);

    expect($status)->toBe(0);
})->skipOnWindows();

it('does not append the health route twice', function (): void {
    $this->artisan('essentials:install', ['--features' => ['health'], '--no-interaction' => true])->assertSuccessful();
    $this->artisan('essentials:install', ['--features' => ['health'], '--force' => true, '--no-interaction' => true])->assertSuccessful();

    $routes = (new Filesystem)->get($this->appPath.'/routes/api.php');

    expect(substr_count($routes, "->name('health');"))->toBe(1);
});

it('creates and registers the api routes file when the application has none', function (): void {
    $files = new Filesystem;
    $files->delete($this->appPath.'/routes/api.php');
    $files->put($this->appPath.'/bootstrap/app.php', <<<'PHP'
<?php

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

PHP);

    $this->artisan('essentials:install', ['--features' => ['api', 'health'], '--no-interaction' => true])
        ->assertSuccessful();

    expect($files->get($this->appPath.'/routes/api.php'))->toContain("->name('health')")
        ->and($files->get($this->appPath.'/bootstrap/app.php'))
        ->toContain("api: __DIR__.'/../routes/api.php',");
});

it('uncomments the api routes argument the skeleton ships commented out', function (): void {
    $files = new Filesystem;
    $files->delete($this->appPath.'/routes/api.php');
    $files->put($this->appPath.'/bootstrap/app.php', <<<'PHP'
<?php

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )->create();

PHP);

    $this->artisan('essentials:install', ['--features' => ['health'], '--no-interaction' => true])
        ->assertSuccessful();

    expect($files->get($this->appPath.'/bootstrap/app.php'))
        ->toContain("        api: __DIR__.'/../routes/api.php',")
        ->not->toContain('// api:');
});

it('creates the client and backoffice route files', function (): void {
    $this->artisan('essentials:install', [
        '--features' => ['route-groups'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    $files = new Filesystem;

    expect($files->exists($this->appPath.'/routes/api/client.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/routes/api/backoffice.php'))->toBeTrue();

    $routes = $files->get($this->appPath.'/routes/api.php');

    expect($routes)
        ->toContain("Route::prefix('client')")
        ->toContain("->name('client.')")
        ->toContain("base_path('routes/api/client.php')")
        ->toContain("Route::prefix('backoffice')")
        ->toContain("base_path('routes/api/backoffice.php')");

    exec('php -l '.escapeshellarg($this->appPath.'/routes/api.php'), $output, $status);

    expect($status)->toBe(0);
})->skipOnWindows();

it('does not register the route groups twice', function (): void {
    $this->artisan('essentials:install', ['--features' => ['route-groups'], '--no-interaction' => true])->assertSuccessful();
    $this->artisan('essentials:install', ['--features' => ['route-groups'], '--force' => true, '--no-interaction' => true])->assertSuccessful();

    $routes = (new Filesystem)->get($this->appPath.'/routes/api.php');

    expect(substr_count($routes, "base_path('routes/api/client.php')"))->toBe(1);
});

it('unregisters web routes that no longer exist', function (): void {
    Process::fake(['git status*' => Process::result(output: '')]);

    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/.git');
    $files->put($this->appPath.'/bootstrap/app.php', <<<'PHP'
<?php

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
    )->create();

PHP);

    $this->artisan('essentials:install', [
        '--features' => [],
        '--cleanup' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/bootstrap/app.php'))
        ->not->toContain("web: __DIR__.'/../routes/web.php'")
        ->toContain("api: __DIR__.'/../routes/api.php'");
});

function stubUserModel(string $appPath): void
{
    $files = new Filesystem;
    $files->ensureDirectoryExists($appPath.'/app/Models');
    $files->put($appPath.'/app/Models/User.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
    ];
}

PHP);
}

it('supercharges the user model for the installed packages', function (): void {
    Process::fake();
    stubUserModel($this->appPath);

    $this->artisan('essentials:install', [
        '--features' => [],
        '--packages' => ['sanctum', 'permission', 'medialibrary', 'activitylog'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    $model = (new Filesystem)->get($this->appPath.'/app/Models/User.php');

    expect($model)
        ->toContain('use Laravel\Sanctum\HasApiTokens;')
        ->toContain('use Spatie\Permission\Traits\HasRoles;')
        ->toContain('use Spatie\MediaLibrary\InteractsWithMedia;')
        ->toContain('use Spatie\MediaLibrary\HasMedia;')
        ->toContain('use Spatie\Activitylog\Models\Concerns\CausesActivity;')
        ->toContain('class User extends Authenticatable implements HasMedia')
        ->toContain('    use HasApiTokens;')
        ->toContain('    use HasRoles;')
        ->toContain('    use InteractsWithMedia;')
        ->toContain('    use CausesActivity;')
        ->toContain('use HasFactory, Notifiable;');

    exec('php -l '.escapeshellarg($this->appPath.'/app/Models/User.php'), $output, $status);

    expect($status)->toBe(0);
})->skipOnWindows();

it('does not add the same user model traits twice', function (): void {
    Process::fake();
    stubUserModel($this->appPath);

    $this->artisan('essentials:install', ['--features' => [], '--packages' => ['sanctum'], '--no-interaction' => true])
        ->assertSuccessful();
    $this->artisan('essentials:install', ['--features' => [], '--packages' => ['sanctum'], '--no-interaction' => true])
        ->assertSuccessful();

    $model = (new Filesystem)->get($this->appPath.'/app/Models/User.php');

    expect(substr_count($model, 'use HasApiTokens;'))->toBe(1)
        ->and(substr_count($model, 'use Laravel\Sanctum\HasApiTokens;'))->toBe(1);
});

it('leaves the user model alone when it does not exist', function (): void {
    Process::fake();

    $this->artisan('essentials:install', ['--features' => [], '--packages' => ['sanctum'], '--no-interaction' => true])
        ->assertSuccessful();

    expect((new Filesystem)->exists($this->appPath.'/app/Models/User.php'))->toBeFalse();
});

it('scaffolds the openapi definitions when l5-swagger is installed', function (): void {
    Process::fake();

    $this->artisan('essentials:install', ['--features' => [], '--packages' => ['swagger'], '--no-interaction' => true])
        ->assertSuccessful();

    $files = new Filesystem;

    expect($files->exists($this->appPath.'/app/OpenApi/Client/OpenApiDefinition.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/OpenApi/Backoffice/OpenApiDefinition.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/OpenApi/Schemas/PaginationSchema.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/OpenApi/Parameters/AcceptLanguageHeaderParameter.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Http/Middleware/ProtectApiDocs.php'))->toBeTrue();

    expect($files->get($this->appPath.'/config/l5-swagger.php'))
        ->toContain("'default' => 'client'")
        ->toContain("base_path('app/OpenApi/Client')")
        ->toContain("base_path('app/OpenApi/Backoffice')")
        ->toContain("env('L5_SWAGGER_USERNAME')")
        ->toContain('ProtectApiDocs::class');
});

it('keeps an existing l5-swagger config unless forced', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/config');
    $files->put($this->appPath.'/config/l5-swagger.php', '<?php return [];');

    $this->artisan('essentials:install', ['--features' => [], '--packages' => ['swagger'], '--no-interaction' => true])
        ->assertSuccessful();

    expect($files->get($this->appPath.'/config/l5-swagger.php'))->toBe('<?php return [];');

    $this->artisan('essentials:install', [
        '--features' => [],
        '--packages' => ['swagger'],
        '--force' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/config/l5-swagger.php'))->toContain("'default' => 'client'");
});

it('declares the documentation credentials in the environment files', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\n");
    $files->put($this->appPath.'/.env.example', "APP_NAME=Laravel\n");

    $this->artisan('essentials:install', ['--features' => [], '--packages' => ['swagger'], '--no-interaction' => true])
        ->assertSuccessful();

    foreach (['.env', '.env.example'] as $file) {
        expect($files->get($this->appPath.'/'.$file))
            ->toContain('APP_NAME=Laravel')
            ->toContain('L5_SWAGGER_ENABLED=true')
            ->toContain('L5_SWAGGER_GENERATE_ALWAYS=true')
            ->toContain('L5_SWAGGER_USERNAME=')
            ->toContain('L5_SWAGGER_PASSWORD=');
    }
});

it('leaves the environment files alone for otp defaults the config already carries', function (): void {
    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\n");

    $this->artisan('essentials:install', ['--features' => ['otp'], '--no-interaction' => true])->assertSuccessful();

    // Every default lives in config/otp.php, so restating it in .env would only
    // give two places to read the same value from.
    expect($files->get($this->appPath.'/.env'))->toBe("APP_NAME=Laravel\n")
        ->and($files->get($this->appPath.'/config/otp.php'))
        ->toContain("env('OTP_LENGTH', 6)")
        ->toContain("env('OTP_TTL', 300)")
        ->toContain("env('OTP_MAX_ATTEMPTS', 5)");
});

it('never overwrites environment values that already exist', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "L5_SWAGGER_USERNAME=admin\nL5_SWAGGER_PASSWORD=secret\n");

    $this->artisan('essentials:install', ['--features' => [], '--packages' => ['swagger'], '--no-interaction' => true])
        ->assertSuccessful();
    $this->artisan('essentials:install', ['--features' => [], '--packages' => ['swagger'], '--no-interaction' => true])
        ->assertSuccessful();

    $env = $files->get($this->appPath.'/.env');

    expect($env)->toContain('L5_SWAGGER_USERNAME=admin')
        ->and($env)->toContain('L5_SWAGGER_PASSWORD=secret')
        ->and(substr_count($env, 'L5_SWAGGER_ENABLED='))->toBe(1)
        ->and(substr_count($env, 'L5_SWAGGER_USERNAME='))->toBe(1);
});

it('registers telescope outside production only', function (): void {
    Process::fake();

    $files = new Filesystem;

    // The state telescope:install leaves behind: the published provider named
    // unconditionally in the manifest, extending a class Composer only
    // installs with --dev.
    $files->put($this->appPath.'/bootstrap/providers.php', <<<'PHP'
    <?php

    return [
        App\Providers\AppServiceProvider::class,
        App\Providers\TelescopeServiceProvider::class,
    ];

    PHP);
    $files->put($this->appPath.'/app/Providers/AppServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class AppServiceProvider extends ServiceProvider
    {
        public function register(): void
        {
            //
        }
    }

    PHP);

    $this->artisan('essentials:install', [
        '--features' => [],
        '--packages' => ['telescope'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    $manifest = $files->get($this->appPath.'/bootstrap/providers.php');
    $provider = $files->get($this->appPath.'/app/Providers/AppServiceProvider.php');

    expect($manifest)->not->toContain('App\Providers\TelescopeServiceProvider::class')
        ->and($manifest)->toContain('App\Providers\AppServiceProvider::class')
        ->and($provider)->toContain("environment('local')")
        ->and($provider)->toContain('class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)')
        ->and($provider)->toContain('$this->app->register(TelescopeServiceProvider::class);');
});

it('leaves the telescope guard alone once it is in place', function (): void {
    Process::fake();

    $files = new Filesystem;
    $arguments = [
        '--features' => [],
        '--packages' => ['telescope'],
        '--no-interaction' => true,
    ];

    $files->put($this->appPath.'/app/Providers/AppServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class AppServiceProvider extends ServiceProvider
    {
        public function register(): void
        {
            //
        }
    }

    PHP);

    $this->artisan('essentials:install', $arguments)->assertSuccessful();
    $this->artisan('essentials:install', $arguments)->assertSuccessful();

    $provider = $files->get($this->appPath.'/app/Providers/AppServiceProvider.php');

    expect(substr_count($provider, "environment('local')"))->toBe(1);
});

it('generates every file before composer is asked for anything', function (): void {
    $files = new Filesystem;
    $generated = null;

    Process::fake(function (PendingProcess $process) use (&$generated, $files): ProcessResult {
        if ($generated === null && str_contains($process->command, 'composer require')) {
            $generated = $files->exists($this->appPath.'/app/Support/ApiResponse.php');
        }

        return Process::result();
    });

    $this->artisan('essentials:install', [
        '--features' => ['api'],
        '--packages' => ['sanctum'],
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($generated)->toBeTrue();
});

it('cleans up a tree the generated files have since dirtied', function (): void {
    $files = new Filesystem;

    // The tree only looks dirty once this command has written its own files
    // into it, so the answer depends entirely on when the question is asked.
    Process::fake(function (PendingProcess $process) use ($files): ProcessResult {
        if (! str_contains($process->command, 'git status')) {
            return Process::result();
        }

        return Process::result(output: $files->exists($this->appPath.'/app/Support/ApiResponse.php')
            ? ' M app/Support/ApiResponse.php'
            : '');
    });

    $files->ensureDirectoryExists($this->appPath.'/.git');
    $files->ensureDirectoryExists($this->appPath.'/resources/views');
    $files->put($this->appPath.'/resources/views/welcome.blade.php', 'hi');

    $this->artisan('essentials:install', [
        '--features' => ['api'],
        '--cleanup' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->exists($this->appPath.'/resources/views/welcome.blade.php'))->toBeFalse()
        ->and($files->exists($this->appPath.'/app/Support/ApiResponse.php'))->toBeTrue();
});

it('sets the application timezone in the config and the environment', function (): void {
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/config');
    $files->put($this->appPath.'/config/app.php', "<?php\n\nreturn [\n    'timezone' => 'UTC',\n];\n");
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--timezone' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/config/app.php'))
        ->toContain("'timezone' => env('APP_TIMEZONE', 'Africa/Algiers')")
        ->and($files->get($this->appPath.'/.env'))->toContain('APP_TIMEZONE=Africa/Algiers');
});

it('leaves a timezone that already reads the environment alone', function (): void {
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/config');
    $files->put($this->appPath.'/config/app.php', "<?php\n\nreturn [\n    'timezone' => env('APP_TIMEZONE', 'UTC'),\n];\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--timezone' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/config/app.php'))->toContain("env('APP_TIMEZONE', 'UTC')");
});

it('does not touch the timezone unless the step was chosen', function (): void {
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/config');
    $files->put($this->appPath.'/config/app.php', "<?php\n\nreturn [\n    'timezone' => 'UTC',\n];\n");

    $this->artisan('essentials:install', ['--features' => [], '--no-interaction' => true])->assertSuccessful();

    expect($files->get($this->appPath.'/config/app.php'))->toContain("'timezone' => 'UTC'");
});

it('drops the framework health route once the generated one replaces it', function (): void {
    Process::fake(['git status*' => Process::result(output: '')]);

    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/.git');
    $files->put($this->appPath.'/bootstrap/app.php', <<<'PHP'
<?php

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

PHP);

    $this->artisan('essentials:install', [
        '--features' => ['api', 'health'],
        '--cleanup' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/bootstrap/app.php'))
        ->not->toContain("health: '/up'")
        ->and($files->get($this->appPath.'/routes/api.php'))->toContain("->name('health')");
});

it('keeps the framework health route when nothing replaces it', function (): void {
    Process::fake(['git status*' => Process::result(output: '')]);

    $files = new Filesystem;
    $files->ensureDirectoryExists($this->appPath.'/.git');
    $files->put($this->appPath.'/bootstrap/app.php', <<<'PHP'
<?php

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )->create();

PHP);

    $this->artisan('essentials:install', [
        '--features' => ['dates'],
        '--cleanup' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/bootstrap/app.php'))->toContain("health: '/up'");
});

it('keeps the strict types declaration first when adding route imports', function (): void {
    $files = new Filesystem;
    $files->delete($this->appPath.'/routes/api.php');

    $this->artisan('essentials:install', ['--features' => ['api', 'health'], '--no-interaction' => true])
        ->assertSuccessful();

    $routes = $files->get($this->appPath.'/routes/api.php');

    expect($routes)->toStartWith("<?php\n\ndeclare(strict_types=1);\n\nuse ")
        ->and(substr_count($routes, 'declare(strict_types=1);'))->toBe(1);

    // The file has to be loadable, which is what the fatal error was about.
    $lint = Process::run([PHP_BINARY, '-l', $this->appPath.'/routes/api.php']);

    expect($lint->successful())->toBeTrue();
});

it('adds route imports after a declaration in a hand written routes file', function (): void {
    $files = new Filesystem;
    $files->put($this->appPath.'/routes/api.php', "<?php\n\ndeclare(strict_types=1);\n");

    $this->artisan('essentials:install', ['--features' => ['api', 'health'], '--no-interaction' => true])
        ->assertSuccessful();

    expect(Process::run([PHP_BINARY, '-l', $this->appPath.'/routes/api.php'])->successful())->toBeTrue();
});

it('installs predis and points the environment files at it', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\nREDIS_CLIENT=phpredis\nCACHE_STORE=database\n");
    $files->put($this->appPath.'/.env.example', "APP_NAME=Laravel\nREDIS_CLIENT=phpredis\nCACHE_STORE=database\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--redis' => 'predis',
        '--redis-cache' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertRan(fn ($process): bool => str_contains($process->command, 'composer require predis/predis'));

    foreach (['.env', '.env.example'] as $file) {
        expect($files->get($this->appPath.'/'.$file))
            ->toContain('REDIS_CLIENT=predis')
            ->toContain('CACHE_STORE=redis')
            ->toContain('REDIS_HOST=127.0.0.1')
            ->toContain('REDIS_PORT=6379')
            ->not->toContain('REDIS_CLIENT=phpredis')
            ->not->toContain('CACHE_STORE=database');
    }
});

it('installs nothing through composer for the phpredis client', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--redis' => 'phpredis',
        '--no-interaction' => true,
    ])->assertSuccessful();

    Process::assertNothingRan();

    expect($files->get($this->appPath.'/.env'))->toContain('REDIS_CLIENT=phpredis');
});

it('leaves the cache store alone unless redis cache was asked for', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\nCACHE_STORE=database\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--redis' => 'phpredis',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/.env'))
        ->toContain('CACHE_STORE=database')
        ->not->toContain('CACHE_STORE=redis');
});

it('replaces a commented out redis client rather than declaring it twice', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\n# REDIS_CLIENT=phpredis\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--redis' => 'predis',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $contents = $files->get($this->appPath.'/.env');

    expect(substr_count($contents, 'REDIS_CLIENT='))->toBe(1)
        ->and($contents)->toContain("REDIS_CLIENT=predis\n");
});

it('declares the redis client when the environment file never mentioned it', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--redis' => 'predis',
        '--redis-cache' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/.env'))
        ->toContain('APP_NAME=Laravel')
        ->toContain('REDIS_CLIENT=predis')
        ->toContain('CACHE_STORE=redis');
});

it('keeps the environment untouched when predis cannot be installed', function (): void {
    Process::fake(['composer*' => Process::result(exitCode: 1)]);

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--redis' => 'predis',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/.env'))->toBe("APP_NAME=Laravel\n");
});

it('keeps redis connection defaults the environment already declares', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\nREDIS_HOST=redis.internal\nREDIS_PASSWORD=secret\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--redis' => 'phpredis',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/.env'))
        ->toContain('REDIS_HOST=redis.internal')
        ->toContain('REDIS_PASSWORD=secret')
        ->not->toContain('REDIS_HOST=127.0.0.1');
});

it('configures no redis client when none was asked for', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--redis' => 'none',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/.env'))->toBe("APP_NAME=Laravel\n");
});

it('leaves redis alone when every feature is generated', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env', "APP_NAME=Laravel\n");

    $this->artisan('essentials:install', ['--all' => true, '--no-interaction' => true])->assertSuccessful();

    expect($files->get($this->appPath.'/.env'))->not->toContain('REDIS_CLIENT');
});

it('rejects a redis client it cannot configure', function (): void {
    Process::fake();

    $this->artisan('essentials:install', [
        '--features' => [],
        '--redis' => 'phpiredis',
        '--no-interaction' => true,
    ])->assertFailed();

    Process::assertNothingRan();
});

it('writes the redis client to every environment file that exists', function (): void {
    Process::fake();

    $files = new Filesystem;
    $files->put($this->appPath.'/.env.example', "APP_NAME=Laravel\n");

    $this->artisan('essentials:install', [
        '--features' => [],
        '--redis' => 'phpredis',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect($files->get($this->appPath.'/.env.example'))->toContain('REDIS_CLIENT=phpredis')
        ->and($files->exists($this->appPath.'/.env'))->toBeFalse();
});
