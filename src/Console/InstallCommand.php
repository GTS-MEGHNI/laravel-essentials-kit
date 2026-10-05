<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Console;

use GtsMeghni\EssentialsKit\Generators\Feature;
use GtsMeghni\EssentialsKit\Generators\ProviderGenerator;
use GtsMeghni\EssentialsKit\Installers\ApiRoutesFile;
use GtsMeghni\EssentialsKit\Installers\BootstrapPatcher;
use GtsMeghni\EssentialsKit\Installers\Cleaner;
use GtsMeghni\EssentialsKit\Installers\DockerStack;
use GtsMeghni\EssentialsKit\Installers\EnvAppender;
use GtsMeghni\EssentialsKit\Installers\Package;
use GtsMeghni\EssentialsKit\Installers\PackageInstaller;
use GtsMeghni\EssentialsKit\Installers\RedisConfigurator;
use GtsMeghni\EssentialsKit\Installers\RouteAppender;
use GtsMeghni\EssentialsKit\Installers\TelescopeGuard;
use GtsMeghni\EssentialsKit\Installers\TimezonePatcher;
use GtsMeghni\EssentialsKit\Installers\Tooling;
use GtsMeghni\EssentialsKit\Installers\UserModelPatcher;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

final class InstallCommand extends Command
{
    /**
     * The banner shown before the first question.
     *
     * @var list<string>
     */
    private const array BANNER = [
        '████ ████ ████ ████ █  █ ████ ████  ██  █    ████      █  █ ████ ████',
        '█    █    █    █    ██ █  ██   ██  █  █ █    █         █ █   ██   ██ ',
        '███  ████ ████ ███  █ ██  ██   ██  ████ █    ████      ██    ██   ██ ',
        '█       █    █ █    █  █  ██   ██  █  █ █       █      █ █   ██   ██ ',
        '████ ████ ████ ████ █  █  ██  ████ █  █ ████ ████      █  █ ████  ██ ',
    ];

    /**
     * The timezone offered by the timezone step.
     */
    private const string TIMEZONE = 'Africa/Algiers';

    /**
     * The fully qualified class name of the generated provider.
     */
    private const string PROVIDER_CLASS = 'App\Providers\EssentialsServiceProvider';

    /**
     * The application relative path of the generated provider.
     */
    private const string PROVIDER_PATH = 'app/Providers/EssentialsServiceProvider.php';

    /**
     * The command signature.
     */
    protected $signature = 'essentials:install
        {--features=* : The features to generate}
        {--packages=* : The Composer packages to install}
        {--redis= : Configure Redis with the given client: none, phpredis, or predis}
        {--redis-cache : Use Redis as the cache store}
        {--tooling : Install Pint, Larastan, Pest, and Boost}
        {--docker : Write the production Docker deployment files}
        {--timezone : Set the application timezone to Africa/Algiers}
        {--cleanup : Remove files a JSON API project does not need}
        {--all : Generate every feature without prompting}
        {--force : Overwrite files that already exist}';

    /**
     * Whether a Composer step failed, so the run exits non-zero.
     *
     * The remaining steps still run, since they do not depend on the one that
     * failed, but a caller such as CI must not read the run as a success.
     */
    private bool $composerFailed = false;

    /**
     * The command description.
     */
    protected $description = 'Install the Laravel Essentials Kit boilerplate into your application.';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $files): int
    {
        $this->banner();

        if (! $this->validRedisOption()) {
            return self::FAILURE;
        }

        // Every question is asked before anything is written, so the developer
        // answers them in one sitting rather than being interrupted by minutes
        // of Composer output between prompts.
        $features = $this->selectedFeatures();
        $packages = $this->selectedPackages();
        $redis = $this->selectedRedis();
        $timezone = $this->wants('timezone', 'Set the application timezone to '.self::TIMEZONE.'?');
        $tooling = $this->wants('tooling', 'Install Pint, Larastan, Pest, and Boost?');
        $docker = $this->wants('docker', 'Write the production Docker deployment files into docker/production?');
        $cleanup = $this->plannedCleanup($files);

        $this->setTimezone($files, $timezone);
        $this->ensureApiRoutes($files, $features, $packages);

        if ($features !== []) {
            $status = $this->generate($files, $features);

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }

        $this->runCleanup($files, $cleanup);

        // After the cleanup step, so the warning about the health route reads the
        // routes file in its final shape.
        $this->writeDockerStack($files, $docker);

        // Composer runs last. It is the slowest and the only step that can fail
        // for reasons outside this package, so everything the kit owns is
        // already on disk by the time the network is involved.
        $installer = new PackageInstaller(
            $this->laravel->basePath(),
            function (string $type, string $output): void {
                $this->output->write($output);
            },
        );

        $this->installPackages($files, $installer, $packages);
        $this->configureRedis($files, $installer, $redis);
        $this->installTooling($files, $installer, $tooling);

        // Last, so minutes of Composer output cannot scroll it out of view: the
        // environment now names a client this PHP cannot load, and every
        // Artisan command fails until the extension is installed.
        $this->reportMissingExtension($redis);

        if ($this->composerFailed) {
            $this->components->error('The installation finished with errors. Fix the Composer failures above and rerun.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Point config/app.php at the requested timezone.
     */
    private function setTimezone(Filesystem $files, bool $wanted): void
    {
        if (! $wanted) {
            return;
        }

        $failure = (new TimezonePatcher($files, $this->laravel->basePath()))->patch(self::TIMEZONE);

        if ($failure !== null) {
            $this->components->warn($failure.' Set the timezone to '.self::TIMEZONE.' there manually.');

            return;
        }

        $added = (new EnvAppender($files, $this->laravel->basePath()))->append(['APP_TIMEZONE' => self::TIMEZONE]);

        $this->components->info($added === []
            ? 'Set the application timezone to '.self::TIMEZONE.' in config/app.php'
            : 'Set the application timezone to '.self::TIMEZONE.' in config/app.php and your environment files.');
    }

    /**
     * Create routes/api.php before anything tries to append to it.
     *
     * The framework only creates this file as a side effect of `install:api`,
     * which installs Sanctum too. Anything appending API routes needs the file
     * to exist first, and it is needed before Composer runs.
     *
     * @param  list<Feature>  $features
     * @param  list<Package>  $packages
     */
    private function ensureApiRoutes(Filesystem $files, array $features, array $packages): void
    {
        $needed = array_filter($features, static fn (Feature $feature): bool => $feature->routeStub !== null) !== []
            || array_filter($packages, static fn (Package $package): bool => $package->key === 'sanctum') !== [];

        if (! $needed) {
            return;
        }

        $result = (new ApiRoutesFile($files, $this->laravel->basePath(), __DIR__.'/../../stubs'))->ensure();

        if ($result['created']) {
            $this->components->info('Created routes/api.php');
        }

        if ($result['failure'] !== null) {
            $this->components->warn($result['failure']." Add api: __DIR__.'/../routes/api.php' to withRouting() manually.");
        }
    }

    /**
     * Print the kit banner, the way the Laravel installer opens.
     */
    private function banner(): void
    {
        if (! $this->input->isInteractive()) {
            return;
        }

        $this->output->newLine();

        foreach (self::BANNER as $line) {
            $this->output->writeln('  <fg=red>'.$line.'</>');
        }

        $this->output->newLine();
        $this->output->writeln('  <fg=gray>Answer everything up front. The kit then writes code you own and steps aside.</>');
        $this->output->newLine();
    }

    /**
     * Write every file the selected features contribute.
     *
     * @param  list<Feature>  $features
     */
    private function generate(Filesystem $files, array $features): int
    {
        $targets = $this->targets($features);
        $existing = array_values(array_filter(
            $targets,
            fn (string $target): bool => $files->exists($this->laravel->basePath($target)),
        ));

        if ($existing !== [] && ! $this->option('force')) {
            $this->components->error('These files already exist. Re-run with --force to overwrite them.');

            foreach ($existing as $target) {
                $this->components->bulletList([$target]);
            }

            return self::FAILURE;
        }

        $this->writeFeatureFiles($files, $features);
        $this->writeProvider($files, $features);
        $this->registerProvider($files);
        $this->patchBootstrap($files, $features);
        $this->appendRoutes($files, $features);

        return self::SUCCESS;
    }

    /**
     * Wire features that register themselves into bootstrap/app.php.
     *
     * @param  list<Feature>  $features
     */
    private function patchBootstrap(Filesystem $files, array $features): void
    {
        $patching = array_filter(
            $features,
            static fn (Feature $feature): bool => $feature->patchesBootstrap,
        );

        if ($patching === []) {
            return;
        }

        $failure = (new BootstrapPatcher($files, $this->laravel->basePath()))->patch();

        if ($failure === null) {
            $this->components->info('Wired the API middleware and exception rendering into bootstrap/app.php');

            return;
        }

        $this->components->warn($failure.' Register the API middleware and ApiExceptionRenderer there manually.');
    }

    /**
     * Append the route stubs contributed by the selected features.
     *
     * @param  list<Feature>  $features
     */
    private function appendRoutes(Filesystem $files, array $features): void
    {
        $appender = new RouteAppender($files, $this->laravel->basePath(), __DIR__.'/../../stubs');

        foreach ($features as $feature) {
            if ($feature->routeStub === null) {
                continue;
            }

            $failure = $appender->append($feature->routeStub, $feature->marker());

            if ($failure !== null) {
                $this->components->warn($failure.' The '.$feature->label.' route was not added.');

                continue;
            }

            $this->components->info('Added the '.$feature->label.' route to routes/api.php');

            if (! $files->exists($this->laravel->basePath('app/Support/ApiResponse.php'))) {
                $this->components->warn('The '.$feature->label.' route uses ApiResponse. Install the API layer feature too.');
            }
        }
    }

    /**
     * Determine whether Composer can run against the current base path.
     */
    private function canInstall(Filesystem $files): bool
    {
        $basePath = $this->laravel->basePath();

        if (str_contains(str_replace('\\', '/', $basePath), '/vendor/')) {
            $this->components->error(
                'The application path is inside a vendor directory ('.$basePath.'). '
                .'Running Composer here would rewrite an installed package and can delete the directory mid-run. '
                .'Run this command from a real Laravel application root.',
            );

            $this->composerFailed = true;

            return false;
        }

        foreach (['composer.json', 'artisan'] as $marker) {
            if ($files->exists($basePath.'/'.$marker)) {
                continue;
            }

            $this->components->error(
                'No '.$marker.' was found in '.$basePath.', so this does not look like a Laravel application root. '
                .'Composer was not run.',
            );

            $this->composerFailed = true;

            return false;
        }

        return true;
    }

    /**
     * Determine whether an optional step was requested by flag or by prompt.
     */
    private function wants(string $option, string $question): bool
    {
        if ($this->option($option) === true) {
            return true;
        }

        return $this->input->isInteractive() && confirm(label: $question, default: true);
    }

    /**
     * Write the production Docker deployment files.
     *
     * These are deployment inputs rather than application code: nothing in the
     * kit reads them, and the developer owns every value in them. The two
     * warnings below are the settings the stack silently depends on.
     */
    private function writeDockerStack(Filesystem $files, bool $wanted): void
    {
        if (! $wanted) {
            return;
        }

        $stack = new DockerStack($files, $this->laravel->basePath(), __DIR__.'/../../stubs');
        $result = $stack->write($this->option('force') === true);

        foreach ($result['created'] as $target) {
            $this->components->info('Created '.$target);
        }

        foreach ($result['kept'] as $target) {
            $this->components->warn('Kept the existing '.$target.'.');
        }

        if (! $stack->trustsProxies()) {
            $this->components->warn(
                'Add $middleware->trustProxies(at: \'*\') to bootstrap/app.php. '
                .'Behind the two nginx layers, an app that does not trust the proxy builds http:// URLs, '
                .'rejects its own signed URLs, and sees the proxy as every client.',
            );
        }

        if (! $stack->hasHealthRoute()) {
            $this->components->warn(
                'The container healthchecks probe GET /api/health, which is not registered. '
                .'Generate the health endpoint feature, or point the probes in '
                .'docker/production/docker-compose.yml and nginx/default.conf at a route that exists.',
            );
        }

        $this->components->info('Read docker/production/README.md before the first deploy.');
    }

    /**
     * Install the selected Composer packages and run their setup commands.
     *
     * @param  list<Package>  $packages
     */
    private function installPackages(Filesystem $files, PackageInstaller $installer, array $packages): void
    {
        if ($packages === [] || ! $this->canInstall($files)) {
            return;
        }

        foreach ([false, true] as $dev) {
            $group = array_values(array_filter(
                $packages,
                static fn (Package $package): bool => $package->dev === $dev,
            ));

            if ($group === []) {
                continue;
            }

            $names = array_map(static fn (Package $package): string => $package->name, $group);

            $this->components->info('Requiring '.implode(', ', $names));

            $result = $installer->require($names, $dev);

            if (! $result->successful()) {
                $this->components->error('Composer failed for '.implode(', ', $names).'. Skipping their setup commands.');
                $this->reportFailure($result);
                $this->composerFailed = true;

                continue;
            }

            foreach ($group as $package) {
                $this->runSetup($installer, $package->setup);
                $this->writePackageFiles($files, $package);
                $this->appendEnv($files, $package);
                $this->patchUserModel($files, $package);
                $this->guardTelescope($files, $package);
            }
        }
    }

    /**
     * Write the scaffold a package contributes, once it is actually installed.
     *
     * These files are written after Composer rather than with the feature
     * files, because they reference classes the package ships and would fatal
     * if the installation had failed.
     */
    private function writePackageFiles(Filesystem $files, Package $package): void
    {
        foreach ($package->files as $stub => $target) {
            $path = $this->laravel->basePath($target);

            if ($files->exists($path) && $this->option('force') !== true) {
                $this->components->warn('Kept the existing '.$target.'. Re-run with --force to overwrite it.');

                continue;
            }

            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, $files->get(__DIR__.'/../../stubs/'.$stub));

            $this->components->info('Created '.$target);
        }
    }

    /**
     * Declare the environment keys a package reads.
     */
    private function appendEnv(Filesystem $files, Package $package): void
    {
        $added = (new EnvAppender($files, $this->laravel->basePath()))->append($package->env);

        if ($added === []) {
            return;
        }

        $this->components->info('Added '.implode(', ', $added).' to your environment files.');
    }

    /**
     * Keep a development-only Telescope from fataling a production deploy.
     */
    private function guardTelescope(Filesystem $files, Package $package): void
    {
        if ($package->key !== 'telescope') {
            return;
        }

        $changed = (new TelescopeGuard($files, $this->laravel->basePath()))->guard();

        if ($changed === []) {
            return;
        }

        $this->components->info('Registered Telescope outside production only, in '.implode(' and ', $changed));
    }

    /**
     * Add a package's traits and interfaces to the application User model.
     */
    private function patchUserModel(Filesystem $files, Package $package): void
    {
        $added = (new UserModelPatcher($files, $this->laravel->basePath()))
            ->patch($package->userTraits, $package->userInterfaces);

        if ($added === []) {
            return;
        }

        $this->components->info('Added '.implode(', ', $added).' to app/Models/User.php');
    }

    /**
     * Resolve the packages to install, prompting when none were given.
     *
     * @return list<Package>
     */
    private function selectedPackages(): array
    {
        $packages = Package::all();

        /** @var list<string> $keys */
        $keys = $this->option('packages');

        if ($keys !== []) {
            return array_values(array_filter(
                $packages,
                static fn (Package $package): bool => in_array($package->key, $keys, true),
            ));
        }

        if (! $this->input->isInteractive()) {
            return [];
        }

        $options = [];

        foreach ($packages as $package) {
            if ($package->question === null) {
                $options[$package->key] = $package->name.' — '.$package->description;
            }
        }

        /** @var list<string> $keys */
        $keys = multiselect(
            label: 'Which packages should be installed?',
            options: $options,
            default: array_keys($options),
            hint: 'Deselect anything you do not want. Composer runs once per group.',
        );

        foreach ($packages as $package) {
            if ($package->question === null) {
                continue;
            }

            $question = $package->question.' ('.$package->name.')';

            if (confirm(label: $question, default: true)) {
                $keys[] = $package->key;
            }
        }

        return array_values(array_filter(
            $packages,
            static fn (Package $package): bool => in_array($package->key, $keys, true),
        ));
    }

    /**
     * Reject a --redis value that names no client the kit can configure.
     */
    private function validRedisOption(): bool
    {
        $client = $this->option('redis');

        if ($client === null || $client === '') {
            return true;
        }

        $allowed = [RedisConfigurator::NONE, RedisConfigurator::PHPREDIS, RedisConfigurator::PREDIS];

        if (in_array($client, $allowed, true)) {
            return true;
        }

        $this->components->error('Unknown --redis client "'.$client.'". Use '.implode(', ', $allowed).'.');

        return false;
    }

    /**
     * Resolve the Redis client to configure, prompting when no flag was given.
     *
     * Redis is infrastructure rather than generated code, so it is left alone
     * unless it was asked for. That is why --all does not turn it on.
     *
     * @return array{client: string, cache: bool}|null Null when Redis is skipped.
     */
    private function selectedRedis(): ?array
    {
        $client = $this->option('redis');

        if (is_string($client) && $client !== '') {
            return $client === RedisConfigurator::NONE
                ? null
                : ['client' => $client, 'cache' => $this->option('redis-cache') === true];
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        if (! confirm(label: 'Will this project use Redis?', default: true)) {
            return null;
        }

        /** @var string $client */
        $client = select(
            label: 'Which Redis client will this project use?',
            options: [
                RedisConfigurator::PHPREDIS => 'phpredis — the PECL extension, faster, installed outside Composer',
                RedisConfigurator::PREDIS => 'predis — a pure PHP package, installed by Composer',
            ],
            default: RedisConfigurator::extensionLoaded()
                ? RedisConfigurator::PHPREDIS
                : RedisConfigurator::PREDIS,
            hint: RedisConfigurator::extensionLoaded()
                ? 'The phpredis extension is loaded on this machine.'
                : 'The phpredis extension is not loaded on this machine.',
        );

        if ($client === RedisConfigurator::PHPREDIS && ! RedisConfigurator::extensionLoaded()) {
            $this->components->warn('phpredis is not installed on this machine. Artisan will fail locally until it is; the install command is shown at the end.');
        }

        return [
            'client' => $client,
            'cache' => confirm(label: 'Use Redis as the cache store?', default: true),
        ];
    }

    /**
     * Install the chosen Redis client and point the environment files at it.
     *
     * @param  array{client: string, cache: bool}|null  $redis
     */
    private function configureRedis(Filesystem $files, PackageInstaller $installer, ?array $redis): void
    {
        if ($redis === null) {
            return;
        }

        if ($redis['client'] === RedisConfigurator::PREDIS) {
            if (! $this->canInstall($files)) {
                return;
            }

            $this->components->info('Requiring '.RedisConfigurator::PREDIS_PACKAGE);

            $result = $installer->require([RedisConfigurator::PREDIS_PACKAGE]);

            // The environment is left untouched on failure, so it never names a
            // client the application cannot load.
            if (! $result->successful()) {
                $this->components->error('Composer failed for '.RedisConfigurator::PREDIS_PACKAGE.'. Redis was not configured.');
                $this->reportFailure($result);
                $this->composerFailed = true;

                return;
            }
        }

        $present = array_filter(
            ['.env', '.env.example'],
            fn (string $file): bool => $files->exists($this->laravel->basePath($file)),
        );

        if ($present === []) {
            $this->components->warn('No environment file was found. Set REDIS_CLIENT='.$redis['client'].' yourself.');

            return;
        }

        $written = (new RedisConfigurator($files, $this->laravel->basePath()))
            ->configure($redis['client'], $redis['cache']);

        $this->components->info($written === []
            ? 'Your environment files already select the '.$redis['client'].' client.'
            : 'Set '.implode(', ', $written).' in your environment files.');
    }

    /**
     * Say how to install phpredis when the extension is not loaded here.
     *
     * The kit only prints the command. Installing a PHP extension needs root,
     * which does not belong to a Composer process running inside a project.
     *
     * @param  array{client: string, cache: bool}|null  $redis
     */
    private function reportMissingExtension(?array $redis): void
    {
        if ($redis === null || $redis['client'] !== RedisConfigurator::PHPREDIS || RedisConfigurator::extensionLoaded()) {
            return;
        }

        $this->components->warn(
            'The phpredis extension is not installed for PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.' on this machine. '
            .'Your environment now sets REDIS_CLIENT=phpredis, so php artisan fails with Class "Redis" not found until you install it:',
        );
        $this->components->bulletList([
            'Debian or Ubuntu: sudo apt install php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'-redis',
            'macOS with Homebrew PHP: pecl install redis',
            'Anywhere else: pecl install redis, then add extension=redis to your php.ini',
            'Docker images: docker-php-ext-enable redis',
            'Or rerun with --redis=predis, which needs no extension',
        ]);
    }

    /**
     * Install the code quality tooling and write its configuration.
     */
    private function installTooling(Filesystem $files, PackageInstaller $installer, bool $wanted): void
    {
        if (! $wanted) {
            return;
        }

        if (! $this->canInstall($files)) {
            return;
        }

        foreach (Tooling::PLUGINS as $plugin) {
            if (! $installer->allowPlugin($plugin)->successful()) {
                $this->components->warn('Could not allow the '.$plugin.' Composer plugin.');
            }
        }

        $this->components->info('Requiring '.implode(', ', Tooling::PACKAGES));

        $result = $installer->require(Tooling::PACKAGES, dev: true);

        if (! $result->successful()) {
            $this->components->error('Composer failed while installing the tooling.');
            $this->reportFailure($result);
            $this->composerFailed = true;

            return;
        }

        foreach (Tooling::FILES as $stub => $target) {
            $path = $this->laravel->basePath($target);

            if ($files->exists($path) && $this->option('force') !== true) {
                $this->components->warn('Kept the existing '.$target.'.');

                continue;
            }

            $files->put($path, $files->get(__DIR__.'/../../stubs/'.$stub));

            $this->components->info('Created '.$target);
        }

        $this->writeComposerScripts($files);
        $this->runSetup($installer, Tooling::SETUP);
    }

    /**
     * Add the tooling scripts to the application composer file.
     */
    private function writeComposerScripts(Filesystem $files): void
    {
        $path = $this->laravel->basePath('composer.json');

        if (! $files->exists($path)) {
            return;
        }

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($files->get($path), true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $scripts */
        $scripts = is_array($manifest['scripts'] ?? null) ? $manifest['scripts'] : [];

        foreach (Tooling::SCRIPTS as $name => $script) {
            $scripts[$name] = $script;
        }

        $manifest['scripts'] = $scripts;

        $files->put($path, json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n");

        $this->components->info('Added the tooling scripts to composer.json');
    }

    /**
     * Show why a process failed, so the developer is not left guessing.
     */
    private function reportFailure(ProcessResult $result): void
    {
        $message = trim($result->errorOutput()) !== ''
            ? trim($result->errorOutput())
            : trim($result->output());

        if ($message !== '') {
            $this->components->bulletList(explode("\n", $message));
        }
    }

    /**
     * Run a list of Artisan setup commands, reporting any that fail.
     *
     * @param  list<string>  $commands
     */
    private function runSetup(PackageInstaller $installer, array $commands): void
    {
        foreach ($commands as $command) {
            if ($installer->artisan($command)->successful()) {
                $this->components->info('Ran php artisan '.$command);

                continue;
            }

            $this->components->warn('php artisan '.$command.' failed. Run it manually.');
        }
    }

    /**
     * Ask which files a JSON API project does not need, without deleting yet.
     *
     * The question is asked before anything is generated, so the git safety
     * check sees the tree as the developer left it rather than one this command
     * has already written to.
     *
     * @return list<string>|null The approved paths, or null when the step is skipped.
     */
    private function plannedCleanup(Filesystem $files): ?array
    {
        if ($this->option('cleanup') !== true && ! $this->input->isInteractive()) {
            return null;
        }

        $cleaner = new Cleaner($files, $this->laravel->basePath());
        $candidates = $cleaner->candidates();

        if ($candidates === []) {
            return [];
        }

        $this->components->info('These files are not needed by a JSON API:');

        foreach ($candidates as $path => $reason) {
            $this->components->twoColumnDetail($path, $reason);
        }

        $dirty = $cleaner->isDirty();

        if ($dirty && $this->option('force') !== true) {
            $this->components->error(
                'The working tree has uncommitted changes, so deleting these could not be undone. '
                .'Commit first, or re-run with --force.',
            );

            return null;
        }

        $this->components->warn($dirty
            ? 'The working tree is not clean, so this cannot be undone with git.'
            : 'The working tree is clean, so this can be undone with git.');

        $paths = array_keys($candidates);

        if ($this->input->isInteractive()
            && ! confirm(label: 'Delete these '.count($paths).' paths permanently?', default: true)) {
            $this->components->info('Nothing will be deleted.');

            return null;
        }

        return $paths;
    }

    /**
     * Delete the paths approved earlier.
     *
     * @param  list<string>|null  $paths
     */
    private function runCleanup(Filesystem $files, ?array $paths): void
    {
        if ($paths === null) {
            return;
        }

        $cleaner = new Cleaner($files, $this->laravel->basePath());

        if ($paths !== []) {
            $cleaner->delete($paths);

            $this->components->info('Deleted '.count($paths).' paths.');
        }

        $this->unregisterWebRoutes($files, $cleaner);
        $this->unregisterFrameworkHealth($files, $cleaner);
    }

    /**
     * Drop the framework health route once the generated one has replaced it.
     *
     * `health: '/up'` answers outside the response envelope and reports nothing
     * but that PHP is running, so it is redundant next to /api/health. It is
     * only removed when the generated route is actually in place, because a
     * removed probe with nothing behind it is worse than a duplicate one.
     */
    private function unregisterFrameworkHealth(Filesystem $files, Cleaner $cleaner): void
    {
        $routes = $this->laravel->basePath('routes/api.php');

        if (! $files->exists($routes) || ! str_contains($files->get($routes), "->name('health')")) {
            return;
        }

        if (! $files->exists($this->laravel->basePath('bootstrap/app.php'))) {
            return;
        }

        if (! str_contains($files->get($this->laravel->basePath('bootstrap/app.php')), 'health:')) {
            return;
        }

        if ($cleaner->removeHealthRouting()) {
            $this->components->info('Removed the framework health route from bootstrap/app.php, replaced by /api/health');

            return;
        }

        $this->components->warn("Remove health: '/up' from withRouting() in bootstrap/app.php manually.");
    }

    /**
     * Drop the web route registration whenever routes/web.php is absent.
     *
     * A bootstrap file registering a route file that no longer exists stops the
     * application from booting at all, so this runs even when there was nothing
     * left to delete.
     */
    private function unregisterWebRoutes(Filesystem $files, Cleaner $cleaner): void
    {
        if ($files->exists($this->laravel->basePath('routes/web.php'))) {
            return;
        }

        if (! $files->exists($this->laravel->basePath('bootstrap/app.php'))) {
            return;
        }

        if (! str_contains($files->get($this->laravel->basePath('bootstrap/app.php')), 'routes/web.php')) {
            return;
        }

        if ($cleaner->removeWebRouting()) {
            $this->components->info('Removed the web route registration from bootstrap/app.php');

            return;
        }

        $this->components->warn('Remove the web route registration from bootstrap/app.php manually.');
    }

    /**
     * Resolve the features to generate, prompting when none were given.
     *
     * @return list<Feature>
     */
    private function selectedFeatures(): array
    {
        $features = Feature::all();

        if ($this->option('all') === true) {
            return $features;
        }

        /** @var list<string> $keys */
        $keys = $this->option('features');

        if ($keys === [] && ! $this->input->isInteractive()) {
            return [];
        }

        if ($keys === []) {
            $options = [];

            foreach ($features as $feature) {
                $options[$feature->key] = $feature->label.' — '.$feature->description;
            }

            /** @var list<string> $keys */
            $keys = multiselect(
                label: 'Which parts of the kit should be written into your application?',
                options: $options,
                default: array_keys($options),
                hint: 'Everything you keep selected becomes editable code you own.',
            );
        }

        return array_values(array_filter(
            $features,
            static fn (Feature $feature): bool => in_array($feature->key, $keys, true),
        ));
    }

    /**
     * List every application path the selected features will write.
     *
     * @param  list<Feature>  $features
     * @return list<string>
     */
    private function targets(array $features): array
    {
        $targets = [];

        foreach ($features as $feature) {
            $targets = [...$targets, ...array_values($feature->files)];
        }

        if (ProviderGenerator::contributing($features) !== []) {
            $targets[] = self::PROVIDER_PATH;
        }

        return array_values(array_unique($targets));
    }

    /**
     * Copy the stub files belonging to the selected features.
     *
     * @param  list<Feature>  $features
     */
    private function writeFeatureFiles(Filesystem $files, array $features): void
    {
        foreach ($features as $feature) {
            foreach ($feature->files as $stub => $target) {
                $path = $this->laravel->basePath($target);

                $files->ensureDirectoryExists(dirname($path));
                $files->put($path, $files->get(__DIR__.'/../../stubs/'.$stub));

                $this->components->info('Created '.$target);
            }
        }
    }

    /**
     * Generate the application service provider for the selected features.
     *
     * @param  list<Feature>  $features
     */
    private function writeProvider(Filesystem $files, array $features): void
    {
        if (ProviderGenerator::contributing($features) === []) {
            return;
        }

        $generator = new ProviderGenerator($files, __DIR__.'/../../stubs');
        $path = $this->laravel->basePath(self::PROVIDER_PATH);

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $generator->render($features));

        $this->components->info('Created '.self::PROVIDER_PATH);
    }

    /**
     * Add the generated provider to the application provider manifest.
     */
    private function registerProvider(Filesystem $files): void
    {
        $path = $this->laravel->basePath('bootstrap/providers.php');

        if (! $files->exists($this->laravel->basePath(self::PROVIDER_PATH))) {
            return;
        }

        if (! $files->exists($path)) {
            $this->components->warn('bootstrap/providers.php was not found. Register '.self::PROVIDER_CLASS.' manually.');

            return;
        }

        $contents = $files->get($path);

        if (str_contains($contents, self::PROVIDER_CLASS)) {
            $this->components->info(self::PROVIDER_CLASS.' is already registered.');

            return;
        }

        $updated = preg_replace(
            '/(\n)(\];)/',
            '$1    '.self::PROVIDER_CLASS.'::class,$1$2',
            $contents,
            1,
        );

        if (! is_string($updated) || $updated === $contents) {
            $this->components->warn('Could not update bootstrap/providers.php. Register '.self::PROVIDER_CLASS.' manually.');

            return;
        }

        $files->put($path, $updated);

        $this->components->info('Registered '.self::PROVIDER_CLASS.' in bootstrap/providers.php');
    }
}
