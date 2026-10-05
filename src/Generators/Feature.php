<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Generators;

use Illuminate\Support\Str;

final class Feature
{
    /**
     * @param  list<string>  $imports  Use statements the provider method needs.
     * @param  array<string, string>  $files  Stub path mapped to its application destination.
     * @param  bool  $registersProvider  Whether this feature contributes a provider register method.
     * @param  bool  $patchesBootstrap  Whether this feature wires itself into bootstrap/app.php.
     * @param  string|null  $routeStub  Route stub appended to routes/api.php.
     * @param  string|null  $routeMarker  Text proving the stub was already appended.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly array $imports = [],
        public readonly array $files = [],
        public readonly bool $bootsProvider = true,
        public readonly bool $registersProvider = false,
        public readonly bool $patchesBootstrap = false,
        public readonly ?string $routeStub = null,
        public readonly ?string $routeMarker = null,
    ) {}

    /**
     * The generated provider method name for this feature.
     */
    public function method(): string
    {
        return 'boot'.Str::studly($this->key);
    }

    /**
     * The generated provider register method name for this feature.
     */
    public function registerMethod(): string
    {
        return 'register'.Str::studly($this->key);
    }

    /**
     * The text that proves this feature's routes are already registered.
     */
    public function marker(): string
    {
        return $this->routeMarker ?? "->name('".$this->key."')";
    }

    /**
     * The stub file holding this feature's generated provider method.
     */
    public function stub(): string
    {
        return $this->key.'.stub';
    }

    /**
     * Every feature the installer can generate, in generated order.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self(
                key: 'database',
                label: 'Database safety',
                description: 'Prohibit destructive migration commands in production.',
                imports: ['Illuminate\Support\Facades\DB'],
            ),
            new self(
                key: 'models',
                label: 'Strict models',
                description: 'Prevent lazy loading and silently discarded attributes outside production.',
                imports: [
                    'Illuminate\Database\Eloquent\Model',
                    'Illuminate\Http\Resources\Json\JsonResource',
                ],
            ),
            new self(
                key: 'dates',
                label: 'Immutable dates',
                description: 'Use CarbonImmutable for every date the framework creates.',
                imports: [
                    'Carbon\CarbonImmutable',
                    'Illuminate\Support\Facades\Date',
                ],
            ),
            new self(
                key: 'security',
                label: 'Security defaults',
                description: 'Strong password rules and no stray HTTP calls in tests.',
                imports: [
                    'Illuminate\Support\Facades\Http',
                    'Illuminate\Validation\Rules\Password',
                ],
            ),
            new self(
                key: 'observability',
                label: 'Slow query logging',
                description: 'Log individual slow queries and slow cumulative request query time.',
                imports: [
                    'Illuminate\Database\Connection',
                    'Illuminate\Database\Events\QueryExecuted',
                    'Illuminate\Support\Facades\DB',
                    'Illuminate\Support\Facades\Log',
                ],
            ),
            new self(
                key: 'api',
                label: 'API layer',
                description: 'Response envelope, global exception rendering, JSON forcing, and request ids.',
                files: [
                    'api/ApiResponse.php.stub' => 'app/Support/ApiResponse.php',
                    'api/ApiExceptionRenderer.php.stub' => 'app/Exceptions/ApiExceptionRenderer.php',
                    'api/ForceJsonResponse.php.stub' => 'app/Http/Middleware/ForceJsonResponse.php',
                    'api/RequestId.php.stub' => 'app/Http/Middleware/RequestId.php',
                ],
                bootsProvider: false,
                patchesBootstrap: true,
            ),
            new self(
                key: 'health',
                label: 'Health endpoint',
                description: 'A GET /api/health route reporting application and database status.',
                bootsProvider: false,
                routeStub: 'health.stub',
            ),
            new self(
                key: 'route-groups',
                label: 'Client and backoffice route groups',
                description: 'Split routes/api.php into prefixed client and backoffice route files.',
                files: [
                    'routes/client.php.stub' => 'routes/api/client.php',
                    'routes/backoffice.php.stub' => 'routes/api/backoffice.php',
                ],
                bootsProvider: false,
                routeStub: 'groups.stub',
                routeMarker: "base_path('routes/api/client.php')",
            ),
            new self(
                key: 'otp',
                label: 'One time passwords',
                description: 'A random code generator, a predictable fake for tests, and a cache backed store.',
                imports: [
                    'App\Support\Otp\FakeOtpGenerator',
                    'App\Support\Otp\OtpGenerator',
                    'App\Support\Otp\OtpStore',
                    'App\Support\Otp\RandomOtpGenerator',
                    'Illuminate\Support\Facades\Cache',
                    'Illuminate\Support\Facades\Config',
                ],
                files: [
                    'otp/OtpGenerator.php.stub' => 'app/Support/Otp/OtpGenerator.php',
                    'otp/RandomOtpGenerator.php.stub' => 'app/Support/Otp/RandomOtpGenerator.php',
                    'otp/FakeOtpGenerator.php.stub' => 'app/Support/Otp/FakeOtpGenerator.php',
                    'otp/OtpStore.php.stub' => 'app/Support/Otp/OtpStore.php',
                    'otp/config.php.stub' => 'config/otp.php',
                ],
                bootsProvider: false,
                registersProvider: true,
            ),
            new self(
                key: 'phone',
                label: 'Algerian phone numbers',
                description: 'E.164 normalization and a validation rule for Algerian mobile numbers.',
                files: [
                    'support/PhoneNumber.php.stub' => 'app/Support/PhoneNumber.php',
                    'support/AlgerianPhoneNumber.php.stub' => 'app/Rules/AlgerianPhoneNumber.php',
                ],
                bootsProvider: false,
            ),
            new self(
                key: 'geo',
                label: 'Algerian provinces and communes',
                description: 'Provinces and communes translated into Arabic, French, and English, with tables and a seeder.',
                files: [
                    'geo/algeria.json.stub' => 'database/data/algeria.json',
                    'geo/create_provinces_table.php.stub' => 'database/migrations/0001_01_01_000010_create_provinces_table.php',
                    'geo/create_communes_table.php.stub' => 'database/migrations/0001_01_01_000011_create_communes_table.php',
                    'geo/Province.php.stub' => 'app/Models/Province.php',
                    'geo/Commune.php.stub' => 'app/Models/Commune.php',
                    'geo/AlgeriaGeoSeeder.php.stub' => 'database/seeders/AlgeriaGeoSeeder.php',
                ],
                bootsProvider: false,
            ),
        ];
    }
}
