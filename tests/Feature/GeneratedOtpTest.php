<?php

declare(strict_types=1);

use App\Providers\EssentialsServiceProvider;
use App\Support\Otp\FakeOtpGenerator;
use App\Support\Otp\OtpGenerator;
use App\Support\Otp\OtpStore;
use App\Support\Otp\RandomOtpGenerator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Filesystem\Filesystem;

/**
 * These tests exercise the code the installer generates, rather than the
 * installer itself, because the OTP classes carry the only generated behavior
 * that a wrong edit would silently weaken instead of breaking loudly.
 */
beforeEach(function (): void {
    $this->appPath = sys_get_temp_dir().'/essentials-kit-otp-'.bin2hex(random_bytes(6));

    (new Filesystem)->ensureDirectoryExists($this->appPath.'/app/Providers');

    $this->app->setBasePath($this->appPath);

    $this->artisan('essentials:install', ['--features' => ['otp'], '--no-interaction' => true])->assertSuccessful();

    // The generated classes live in the application namespace, which nothing
    // autoloads from a temporary directory, and the class names are the same in
    // every test, so each file is loaded exactly once for the whole run.
    foreach (['OtpGenerator', 'RandomOtpGenerator', 'FakeOtpGenerator', 'OtpStore'] as $class) {
        if (! class_exists('App\Support\Otp\\'.$class)) {
            require $this->appPath.'/app/Support/Otp/'.$class.'.php';
        }
    }

    if (! class_exists(EssentialsServiceProvider::class)) {
        require $this->appPath.'/app/Providers/EssentialsServiceProvider.php';
    }
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->appPath);
});

function otpStore(int $length = 6, int $ttl = 300, int $maxAttempts = 3, ?object $generator = null): object
{
    return new OtpStore(
        $generator ?? new FakeOtpGenerator,
        new Repository(new ArrayStore),
        $length,
        $ttl,
        $maxAttempts,
    );
}

it('writes the otp files and configuration without a boot method', function (): void {
    $files = new Filesystem;

    expect($files->exists($this->appPath.'/app/Support/Otp/OtpGenerator.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Support/Otp/RandomOtpGenerator.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Support/Otp/FakeOtpGenerator.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/app/Support/Otp/OtpStore.php'))->toBeTrue()
        ->and($files->exists($this->appPath.'/config/otp.php'))->toBeTrue();

    $provider = $files->get($this->appPath.'/app/Providers/EssentialsServiceProvider.php');

    expect($provider)
        ->toContain('public function register(): void')
        ->toContain('$this->registerOtp();')
        ->toContain('private function registerOtp(): void')
        ->toContain('OtpGenerator::class,')
        ->toContain('$this->app->singleton(OtpStore::class')
        // Nothing in this feature belongs in boot(), so no empty one is written.
        ->and($provider)->not->toContain('$this->bootOtp();')
        ->and($provider)->not->toContain('public function boot(): void');
});

it('picks the predictable generator by environment and nothing else', function (): void {
    $provider = (new Filesystem)->get($this->appPath.'/app/Providers/EssentialsServiceProvider.php');

    // No configuration flag exists to select the fake, because one copied
    // environment file would then turn every production code into the same
    // digits. Staging is left on real codes so a flow can be rehearsed there.
    expect($provider)
        ->toContain("\$this->app->environment(['local', 'testing'])")
        ->toContain('? new FakeOtpGenerator')
        ->toContain(': new RandomOtpGenerator,')
        ->and($provider)->not->toContain('OTP_FAKE')
        ->and($provider)->not->toContain("config('otp.fake')");
});

it('resolves predictable codes in local and testing only', function (string $environment, string $expected): void {
    $this->app['env'] = $environment;

    $this->app->register(new EssentialsServiceProvider($this->app));

    expect($this->app->make(OtpGenerator::class))->toBeInstanceOf($expected);
})->with([
    'local' => ['local', FakeOtpGenerator::class],
    'testing' => ['testing', FakeOtpGenerator::class],
    // Staging runs real codes, so a verification flow is rehearsed there as it
    // will behave once it ships.
    'staging' => ['staging', RandomOtpGenerator::class],
    'production' => ['production', RandomOtpGenerator::class],
]);

it('resolves a store built from the configuration', function (): void {
    config()->set('otp', require $this->appPath.'/config/otp.php');

    $this->app->register(new EssentialsServiceProvider($this->app));

    expect($this->app->make(OtpStore::class))->toBeInstanceOf(OtpStore::class)
        ->and($this->app->make(OtpStore::class))->toBe($this->app->make(OtpStore::class));
});

it('generates a code of the configured length made only of digits', function (): void {
    $generator = new RandomOtpGenerator;

    foreach ([4, 6, 8] as $length) {
        $code = $generator->generate($length);

        expect($code)->toHaveLength($length)
            ->and($code)->toMatch('/^\d+$/');
    }
});

it('generates codes that differ from one another', function (): void {
    $generator = new RandomOtpGenerator;

    $codes = array_map(static fn (): string => $generator->generate(6), range(1, 20));

    expect(count(array_unique($codes)))->toBeGreaterThan(1);
});

it('generates the same predictable code every time when faked', function (): void {
    $generator = new FakeOtpGenerator;

    expect($generator->generate(6))->toBe('111111')
        ->and($generator->generate(6))->toBe('111111')
        ->and($generator->generate(4))->toBe('1111');
});

it('issues a code and verifies it once', function (): void {
    $store = otpStore();

    $code = $store->issue('+213555000001');

    expect($code)->toBe('111111')
        ->and($store->verify('+213555000001', $code))->toBeTrue()
        // Consumed on success, so a replayed request cannot reuse it.
        ->and($store->verify('+213555000001', $code))->toBeFalse();
});

it('keeps codes issued for different identifiers apart', function (): void {
    $store = otpStore(generator: new RandomOtpGenerator);

    $first = $store->issue('+213555000001');
    $store->issue('+213555000002');

    expect($store->verify('+213555000002', $first))->toBeFalse()
        ->and($store->verify('+213555000001', $first))->toBeTrue();
});

it('never stores the code itself', function (): void {
    $cache = new Repository(new ArrayStore);

    $store = new OtpStore(new FakeOtpGenerator, $cache, 6, 300, 3);

    $code = $store->issue('+213555000001');

    // Whatever the cache holds must not be the code, or a cache dump hands over
    // every pending verification.
    expect($cache->get('otp:'.hash('xxh128', '+213555000001').':code'))
        ->toBeString()
        ->not->toBe($code);
});

it('rejects a wrong code without consuming the right one', function (): void {
    $store = otpStore();

    $store->issue('+213555000001');

    expect($store->verify('+213555000001', '999999'))->toBeFalse()
        ->and($store->verify('+213555000001', '111111'))->toBeTrue();
});

it('throws the code away once the attempts run out', function (): void {
    $store = otpStore(maxAttempts: 3);

    $store->issue('+213555000001');

    expect($store->verify('+213555000001', '999999'))->toBeFalse()
        ->and($store->verify('+213555000001', '999999'))->toBeFalse()
        ->and($store->verify('+213555000001', '999999'))->toBeFalse()
        // The third failure spent the last attempt, so even the real code is dead.
        ->and($store->verify('+213555000001', '111111'))->toBeFalse();
});

it('gives a fresh attempt budget to a reissued code', function (): void {
    $store = otpStore(maxAttempts: 2);

    $store->issue('+213555000001');
    $store->verify('+213555000001', '999999');
    $store->issue('+213555000001');

    expect($store->verify('+213555000001', '999999'))->toBeFalse()
        ->and($store->verify('+213555000001', '111111'))->toBeTrue();
});

it('stops verifying once the code has expired', function (): void {
    $store = otpStore(ttl: 60);

    $store->issue('+213555000001');

    $this->travel(61)->seconds();

    expect($store->verify('+213555000001', '111111'))->toBeFalse();
});

it('verifies nothing when no code was issued', function (): void {
    expect(otpStore()->verify('+213555000001', '111111'))->toBeFalse();
});

it('forgets a code on request', function (): void {
    $store = otpStore();

    $store->issue('+213555000001');
    $store->forget('+213555000001');

    expect($store->verify('+213555000001', '111111'))->toBeFalse();
});

it('refuses configuration that would issue guessable or endless codes', function (int $length, int $ttl, int $maxAttempts): void {
    expect(static fn (): object => otpStore($length, $ttl, $maxAttempts))
        ->toThrow(InvalidArgumentException::class);
})->with([
    // An unset OTP_LENGTH reads as zero, which would issue an empty code.
    'no digits' => [0, 300, 3],
    'too few digits' => [3, 300, 3],
    'no lifetime' => [6, 0, 3],
    'no attempt limit' => [6, 300, 0],
]);
