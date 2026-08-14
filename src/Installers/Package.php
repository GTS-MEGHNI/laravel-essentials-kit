<?php

declare(strict_types=1);

namespace GtsMeghni\EssentialsKit\Installers;

final class Package
{
    /**
     * @param  list<string>  $setup  Artisan commands run after the package is required.
     * @param  string|null  $question  Asked as a confirmation instead of listed in the multiselect.
     * @param  list<string>  $userTraits  Traits added to the application User model.
     * @param  list<string>  $userInterfaces  Interfaces added to the application User model.
     * @param  array<string, string>  $files  Stub path mapped to its application destination,
     *                                        written only once the package is installed.
     * @param  array<string, string>  $env  Environment keys mapped to their default value,
     *                                      appended to .env and .env.example.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $label,
        public readonly string $description,
        public readonly bool $dev = false,
        public readonly array $setup = [],
        public readonly ?string $question = null,
        public readonly array $userTraits = [],
        public readonly array $userInterfaces = [],
        public readonly array $files = [],
        public readonly array $env = [],
    ) {}

    /**
     * Every package the installer can install, in installation order.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            new self(
                key: 'sanctum',
                name: 'laravel/sanctum',
                label: 'Laravel Sanctum',
                description: 'API token and SPA authentication.',
                // Not install:api, which would require Sanctum through Composer
                // a second time and prompt to run migrations mid-install. The
                // kit creates routes/api.php and adds HasApiTokens itself, so
                // publishing the migration is all that is left.
                setup: ['vendor:publish --tag=sanctum-migrations'],
                userTraits: ['Laravel\Sanctum\HasApiTokens'],
            ),
            new self(
                key: 'telescope',
                name: 'laravel/telescope',
                label: 'Laravel Telescope',
                description: 'Local request, query, and job debugging.',
                dev: true,
                setup: ['telescope:install'],
            ),
            new self(
                key: 'permission',
                name: 'spatie/laravel-permission',
                label: 'Spatie Permission',
                description: 'Roles and permissions.',
                setup: ['vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"'],
                userTraits: ['Spatie\Permission\Traits\HasRoles'],
            ),
            new self(
                key: 'medialibrary',
                name: 'spatie/laravel-medialibrary',
                label: 'Spatie Media Library',
                description: 'File uploads associated with Eloquent models.',
                setup: ['vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider"'],
                userTraits: ['Spatie\MediaLibrary\InteractsWithMedia'],
                userInterfaces: ['Spatie\MediaLibrary\HasMedia'],
            ),
            new self(
                key: 'activitylog',
                name: 'spatie/laravel-activitylog',
                label: 'Spatie Activity Log',
                description: 'Audit trail of model and user activity.',
                setup: ['vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider"'],
                userTraits: ['Spatie\Activitylog\Models\Concerns\CausesActivity'],
            ),
            new self(
                key: 'query-builder',
                name: 'spatie/laravel-query-builder',
                label: 'Spatie Query Builder',
                description: 'Filtering, sorting, and including from query string parameters.',
                setup: ['vendor:publish --provider="Spatie\QueryBuilder\QueryBuilderServiceProvider"'],
            ),
            new self(
                key: 'excel',
                name: 'maatwebsite/excel',
                label: 'Laravel Excel',
                description: 'Spreadsheet imports and exports.',
                setup: ['vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider"'],
            ),
            new self(
                key: 'swagger',
                name: 'darkaonline/l5-swagger',
                label: 'L5 Swagger',
                description: 'OpenAPI documentation, split into client and backoffice definitions.',
                files: [
                    'openapi/l5-swagger.php.stub' => 'config/l5-swagger.php',
                    'openapi/ProtectApiDocs.php.stub' => 'app/Http/Middleware/ProtectApiDocs.php',
                    'openapi/client/OpenApiDefinition.php.stub' => 'app/OpenApi/Client/OpenApiDefinition.php',
                    'openapi/client/OpenApiConfig.php.stub' => 'app/OpenApi/Client/OpenApiConfig.php',
                    'openapi/client/endpoints/HealthEndpoint.php.stub' => 'app/OpenApi/Client/Endpoints/HealthEndpoint.php',
                    'openapi/backoffice/OpenApiDefinition.php.stub' => 'app/OpenApi/Backoffice/OpenApiDefinition.php',
                    'openapi/backoffice/OpenApiConfig.php.stub' => 'app/OpenApi/Backoffice/OpenApiConfig.php',
                    'openapi/backoffice/endpoints/HealthEndpoint.php.stub' => 'app/OpenApi/Backoffice/Endpoints/HealthEndpoint.php',
                    'openapi/schemas/ErrorResponseSchema.php.stub' => 'app/OpenApi/Schemas/ErrorResponseSchema.php',
                    'openapi/schemas/ValidationErrorResponseSchema.php.stub' => 'app/OpenApi/Schemas/ValidationErrorResponseSchema.php',
                    'openapi/schemas/PaginationSchema.php.stub' => 'app/OpenApi/Schemas/PaginationSchema.php',
                    'openapi/schemas/PaginationMetaSchema.php.stub' => 'app/OpenApi/Schemas/PaginationMetaSchema.php',
                    'openapi/parameters/AcceptLanguageHeaderParameter.php.stub' => 'app/OpenApi/Parameters/AcceptLanguageHeaderParameter.php',
                ],
                env: [
                    'L5_SWAGGER_ENABLED' => 'true',
                    'L5_SWAGGER_GENERATE_ALWAYS' => 'true',
                    'L5_SWAGGER_USERNAME' => '',
                    'L5_SWAGGER_PASSWORD' => '',
                ],
            ),
            new self(
                key: 'satim',
                name: 'gts-meghni/laravel-satim',
                label: 'Laravel SATIM',
                description: 'SATIM payment gateway integration.',
                question: 'Does this project take payments through SATIM?',
            ),
            new self(
                key: 'captcha',
                name: 'gts-meghni/laravel-captcha',
                label: 'Laravel Captcha',
                description: 'Captcha challenge and verification.',
                question: 'Does this project need captcha protection?',
            ),
        ];
    }
}
