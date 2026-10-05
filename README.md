<div align="center">
    <h1>Laravel Essentials Kit</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/gts-meghni/laravel-essentials-kit"><img src="https://img.shields.io/packagist/v/gts-meghni/laravel-essentials-kit.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/gts-meghni/laravel-essentials-kit"><img src="https://img.shields.io/packagist/php-v/gts-meghni/laravel-essentials-kit.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/gts-meghni/laravel-essentials-kit"><img src="https://badge.laravel.cloud/badge/gts-meghni/laravel-essentials-kit?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/gts-meghni/laravel-essentials-kit/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/gts-meghni/laravel-essentials-kit/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/gts-meghni/laravel-essentials-kit"><img src="https://img.shields.io/packagist/dt/gts-meghni/laravel-essentials-kit.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Kick off a Laravel API with the boilerplate already written: hardened framework defaults, a consistent JSON response envelope, global exception rendering, the packages you always install, and the quality gates you always configure.

Everything this kit installs is written into your application as ordinary, editable code. Nothing runs from inside the package, and nothing is hidden behind configuration you cannot read.

## Installation

Install the kit as a development dependency, since it does its work once and then steps aside:

```bash
composer require --dev gts-meghni/laravel-essentials-kit
```

Then run the installer:

```bash
php artisan essentials:install
```

The installer asks before every step, with everything preselected so you deselect what you do not want. Every question is asked first, in one sitting, and only then does anything happen. Composer runs last, so a slow or failing network never interrupts a prompt, and the cleanup step's git safety check sees your working tree as you left it rather than one the installer has already written to.

A non-interactive run does nothing unless you name the steps explicitly with flags.

## What It Installs

### Framework defaults

Selected defaults are generated into `app/Providers/EssentialsServiceProvider.php`, which is registered in `bootstrap/providers.php`. Only the groups you pick are written, so the file contains no dead code.

| Feature | What it generates |
|---|---|
| Database safety | `DB::prohibitDestructiveCommands()` in production |
| Strict models | `Model::shouldBeStrict()` outside production, unwrapped JSON resources |
| Immutable dates | `Date::use(CarbonImmutable::class)` |
| Security defaults | Strong password rules, no stray HTTP calls in tests |
| Slow query logging | Per-query and cumulative query time warnings |

### API layer

Selecting the API feature generates four files and the provider method that wires them:

| File | Purpose |
|---|---|
| `app/Support/ApiResponse.php` | `success()`, `error()`, `paginated()`, and `noContent()` returning a consistent envelope |
| `app/Exceptions/ApiExceptionRenderer.php` | Converts every uncaught throwable into that envelope |
| `app/Http/Middleware/ForceJsonResponse.php` | Forces JSON content negotiation so errors never render as HTML |
| `app/Http/Middleware/RequestId.php` | Adds a request id to the log context and the response headers |

The installer wires these into your `bootstrap/app.php`, which is where Laravel 11+ expects middleware and exception rendering to be configured:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');

    $middleware->append(ForceJsonResponse::class);
    $middleware->append(RequestId::class);
})
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(static fn (): bool => true);
    $exceptions->render(new ApiExceptionRenderer);
})
```

`trustProxies(at: '*')` is part of the same patch because an API behind a reverse proxy is broken without it, quietly: Laravel ignores `X-Forwarded-Proto` until the proxy is trusted, so `url()` builds `http://` links, signed URLs 403 against their own signature, and `$request->ip()` returns the proxy, giving every client one rate limit bucket and one address in the logs. `'*'` is correct while the application is only reachable through that proxy; narrow it if the application is ever published directly. This is also why the kit does not call `URL::forceScheme('https')`, which fixes the URLs while leaving the client IP wrong.

Registering from a service provider instead would look tidier, but it breaks wherever the exception handler is decorated. Collision does exactly that in console and test contexts, which would silently disable the envelope in your feature tests. The patch is skipped if it is already present, and if your bootstrap file has been reshaped the installer says so and leaves it alone.

Responses look like this:

```json
{
    "success": true,
    "message": "Request completed successfully.",
    "data": [],
    "meta": {
        "pagination": {
            "total": 42,
            "count": 15,
            "per_page": 15,
            "current_page": 1,
            "last_page": 3,
            "has_more_pages": true
        }
    }
}
```

```json
{
    "success": false,
    "message": "The email field is required.",
    "errors": {
        "email": ["The email field is required."]
    }
}
```

Empty sections are omitted, and a `meta.debug` block carrying the exception class, file, and line is included outside production only.

To localize error messages per route group, fill in the `messages()` method in the generated renderer. It maps request patterns to translation keys, per exception category, and the first matching pattern wins.

### Client and backoffice route groups

Splits your API surface in two, since most projects serve a client application and an administration panel from the same codebase:

```php
// routes/api.php
Route::prefix('client')
    ->name('client.')
    ->group(base_path('routes/api/client.php'));

Route::prefix('backoffice')
    ->name('backoffice.')
    ->group(base_path('routes/api/backoffice.php'));
```

Routes then live at `/api/client/…` and `/api/backoffice/…`, named `client.*` and `backoffice.*`. The two files are generated empty with a header comment, ready for your routes.

### Health endpoint

Appends a `GET /api/health` route to your own `routes/api.php`, reporting application and database status through the same envelope:

```json
{
    "success": true,
    "message": "Service is healthy.",
    "data": {
        "status": "ok",
        "database": "ok"
    }
}
```

It requires the API layer feature for `ApiResponse`. If `routes/api.php` does not exist yet the installer creates it and adds `api:` to `withRouting()` in `bootstrap/app.php`, so `php artisan install:api` is not needed first.

The framework's own `health: '/up'` route is removed by the API-only cleanup step, but only once this route is in place, since a removed probe with nothing behind it is worse than a duplicate one. Keep `'/up'` if you rely on it answering during `php artisan down`, which `/api/health` does not.

### One time passwords

An optional feature generating a code generator, a predictable fake, and a cache backed store:

```
app/Support/Otp/OtpGenerator.php
app/Support/Otp/RandomOtpGenerator.php
app/Support/Otp/FakeOtpGenerator.php
app/Support/Otp/OtpStore.php
config/otp.php
```

`OtpGenerator` produces digits and nothing else. The lifecycle of storing, expiring, comparing, and counting attempts belongs to `OtpStore`, so a test can swap in predictable digits without also replacing the behavior it is trying to exercise:

```php
$code = app(OtpStore::class)->issue($user->phone);   // send this yourself

app(OtpStore::class)->verify($user->phone, $request->string('code')->toString());
```

Both are bound in the generated provider's `register()` method. `verify()` consumes the code on success, so a replayed request cannot reuse it, and spends one attempt on failure until the code is thrown away entirely.

Only a hash of the code is cached, and the identifier is hashed into the cache key, so neither a cache dump nor a key listing hands over pending verifications. A six digit code has a million possibilities, which is why `OTP_MAX_ATTEMPTS` rather than the length is what keeps it safe.

Delivery is deliberately not included. The store returns the code and your application decides how to send it, which keeps the kit free of an SMS dependency.

Defaults live in `config/otp.php`, so set one of these only to override it:

| Key | Default | Effect |
|---|---|---|
| `OTP_LENGTH` | `6` | Digits per code |
| `OTP_TTL` | `300` | Seconds a code stays valid |
| `OTP_MAX_ATTEMPTS` | `5` | Wrong guesses a code survives |
| `OTP_STORE` | default store | Cache store holding codes |

`FakeOtpGenerator` returns `111111`, which makes verification flows testable and locally usable without an SMS gateway. It is chosen by environment, `local` and `testing` only, and there is deliberately no flag to enable it, because a flag reaching production through a copied environment file would turn every code into the same guessable digits. Staging gets real codes for the same reason: a flow should be rehearsed there as it will behave once it ships.

Out of range configuration is refused at construction instead of degrading: an unset `OTP_LENGTH` reads as zero, and an empty code would verify against anything.

### Algerian phone numbers

An optional feature generating `app/Support/PhoneNumber.php`, which normalizes `+213…`, `213…`, and `0…` numbers to canonical E.164, and `app/Rules/AlgerianPhoneNumber.php` for validation. This one is Algeria specific by design.

### Algerian provinces and communes

An optional feature shipping the full administrative division as seed data, since almost every Algerian project needs it and every project rebuilds it by hand:

```
database/data/algeria.json
database/migrations/0001_01_01_000010_create_provinces_table.php
database/migrations/0001_01_01_000011_create_communes_table.php
database/seeders/AlgeriaGeoSeeder.php
app/Models/Province.php
app/Models/Commune.php
```

69 provinces and 1559 communes, each name translated into Arabic, French, and English, stored in a `name` JSON column and cast to an array:

```php
php artisan migrate
php artisan db:seed --class=AlgeriaGeoSeeder

Province::where('code', '16')->first()->translatedName();  // "الجزائر" under the ar locale
$commune->translatedName('fr');
$province->communes;
```

`translatedName()` falls back to French rather than to the application fallback locale, because every row is guaranteed to carry French. Re-running the seeder updates rather than duplicates, so it is safe in a deploy script.

Provinces are keyed by their zero padded wilaya code. Codes `01` to `58` are the wilayas proper; `59` to `69` are the delegated administrative districts created in 2019, whose communes are also listed under their parent wilaya. Communes keep the id carried by the dataset instead of an autoincrement, so rows referencing them survive a fresh install.

### Packages

The installer offers to install and set up the packages an API project usually needs:

`laravel/sanctum`, `laravel/telescope`, `spatie/laravel-permission`, `spatie/laravel-medialibrary`, `spatie/laravel-activitylog`, `spatie/laravel-query-builder`, `maatwebsite/excel`, `darkaonline/l5-swagger`

Two more are asked as questions rather than listed, because they depend on the project rather than on preference: `gts-meghni/laravel-satim` for SATIM payments, and `gts-meghni/laravel-captcha` for captcha protection.

Each selected package is required through Composer, then its own publish or install command runs in a fresh process. If Composer fails, the setup commands are skipped and the failure is reported.

Packages that expect something on your `User` model get it wired automatically, since a published migration without the matching trait leaves the package inert:

| Package | Added to `app/Models/User.php` |
|---|---|
| `laravel/sanctum` | `HasApiTokens` |
| `spatie/laravel-permission` | `HasRoles` |
| `spatie/laravel-medialibrary` | `InteractsWithMedia`, `implements HasMedia` |
| `spatie/laravel-activitylog` | `CausesActivity` |

Each is added once and skipped if already present, so re-running is safe.

Telescope is installed as a development dependency, and `telescope:install` registers its published provider in `bootstrap/providers.php` unconditionally. That provider extends a class the package ships, so a production deploy running `composer install --no-dev` fatals on every request before the application boots. The installer moves the registration out of the manifest and into `AppServiceProvider::register()` instead:

```php
if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
    $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
    $this->app->register(TelescopeServiceProvider::class);
}
```

The `class_exists` check is deliberate belt and braces: an environment misconfigured as `local` in production would otherwise still reach for a class Composer never installed.

### OpenAPI documentation

Selecting `darkaonline/l5-swagger` generates a documentation scaffold rather than leaving you with the package's single default definition, because a project serving a client application and an administration panel should not publish one document describing both:

```
config/l5-swagger.php
app/Http/Middleware/ProtectApiDocs.php
app/OpenApi/Client/OpenApiDefinition.php
app/OpenApi/Client/OpenApiConfig.php
app/OpenApi/Client/Endpoints/HealthEndpoint.php
app/OpenApi/Backoffice/OpenApiDefinition.php
app/OpenApi/Backoffice/OpenApiConfig.php
app/OpenApi/Backoffice/Endpoints/HealthEndpoint.php
app/OpenApi/Schemas/ErrorResponseSchema.php
app/OpenApi/Schemas/ValidationErrorResponseSchema.php
app/OpenApi/Schemas/PaginationSchema.php
app/OpenApi/Schemas/PaginationMetaSchema.php
app/OpenApi/Parameters/AcceptLanguageHeaderParameter.php
```

Two definitions are configured, each scanning its own directory plus the shared schemas and parameters, so backoffice operations never appear in the client document:

| Definition | UI | JSON |
|---|---|---|
| Client | `/api/documentation/client` | `/api/docs/client` |
| Backoffice | `/api/documentation/backoffice` | `/api/docs/backoffice` |

Operations are written as attributes on classes, one `final readonly class` per endpoint holding nothing but its attribute, which keeps the annotations out of your controllers and lets you delete a document without touching application code. The generated `HealthEndpoint` classes document the route the health feature appends, and are meant to be copied as the template for the rest. Security schemes are declared on each definition's `OpenApiConfig` class instead of in the config file, so `securityDefinitions` is left empty.

The schemas match the envelope the API layer generates, so a documented error or paginated response stays in step with what `ApiResponse` actually returns. Reference them rather than restating the shape per endpoint:

```php
new OA\Response(
    response: 422,
    description: 'The request was invalid.',
    content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse'),
),
```

`ProtectApiDocs` guards the UI, the JSON documents, the assets, and the OAuth2 callback, since documentation exposes your entire API surface and l5-swagger publishes it unauthenticated by default:

| Key | Default | Effect |
|---|---|---|
| `L5_SWAGGER_ENABLED` | `true` | Set to `false` and every documentation route returns 404 |
| `L5_SWAGGER_USERNAME` | empty | HTTP basic user demanded before the docs are served |
| `L5_SWAGGER_PASSWORD` | empty | HTTP basic password |
| `L5_SWAGGER_GENERATE_ALWAYS` | `true` | Rebuild the documents per request |

Leaving the credentials empty leaves the docs open, which is why the installer appends all four keys to `.env` and `.env.example`: an empty value you can see is likelier to get filled in than a key you never knew existed. Set both in production. Credentials are compared with `hash_equals`, so a wrong guess does not leak their length in timing.

Set `L5_SWAGGER_GENERATE_ALWAYS=false` in production and generate during deployment instead, since regenerating on every request parses your whole annotation tree per request:

```bash
php artisan l5-swagger:generate --all
```

### Timezone

Optionally sets the application timezone to `Africa/Algiers`. The Laravel skeleton hardcodes `'timezone' => 'UTC'` in `config/app.php`, so setting `APP_TIMEZONE` alone does nothing. This step rewrites the entry to read the environment, and declares the key:

```php
'timezone' => env('APP_TIMEZONE', 'Africa/Algiers'),
```

A deployment can still override it per environment, and a config that already reads `env()` is left alone.

### Redis

Optionally selects a Redis client and writes it to your environment files. Two clients speak the same protocol, and Laravel supports both:

| Client | Installed by | Notes |
|---|---|---|
| `phpredis` | `pecl install redis` | A C extension. Faster, and what Laravel recommends. |
| `predis` | Composer | Pure PHP. Works anywhere, no extension needed. |

The default answer follows whichever client this machine can already load, but the choice is yours: the installer runs on a development machine, which says nothing about where the application deploys.

Choosing `predis` requires `predis/predis` through Composer. Choosing `phpredis` installs nothing, because building a PECL extension needs root, the PHP development headers, and a `php.ini` edit. The kit prints the install command instead, and only when the extension is missing. It warns right after the prompt and again as the last thing the installer prints, since the environment then names a client this PHP cannot load and every Artisan command fails with `Class "Redis" not found` until the extension is installed.

A second question offers Redis as the cache store, which sets `CACHE_STORE=redis`. `QUEUE_CONNECTION` and `SESSION_DRIVER` are deliberately left alone. A Redis queue needs a supervised worker, retry and backoff tuning, and usually Horizon, none of which the kit configures, and sessions do not apply to a token authenticated API.

`REDIS_HOST`, `REDIS_PORT`, and `REDIS_PASSWORD` are added only when your environment files do not already declare them, so real credentials are never overwritten.

### Quality tooling

Optionally installs Pint, Larastan, Pest with the Laravel and type coverage plugins, and Laravel Boost. It writes `pint.json` and a `phpstan.neon` set to `level: max`, then merges these scripts into your `composer.json` without touching what is already there:

```bash
composer lint         # format
composer lint:check   # verify formatting
composer analyse      # Larastan at level max
composer test:types   # 100% type coverage
composer test:unit    # the suite
composer test         # all of the above, in order
```

### API-only cleanup

Optionally removes what a JSON API does not need: Blade views, frontend assets and build configuration, browser routes, and the view and session configuration files.

This step deletes files permanently, and like every other step it is preselected. It lists every path with a reason before asking, and refuses to run at all when `git status` is not clean or the directory is not a git repository, so there is always a way back. When `routes/web.php` is removed, the `web:` argument is also stripped from `withRouting()` in `bootstrap/app.php`, and `health: '/up'` is stripped once `/api/health` has replaced it.

### Production Docker deployment

Optionally writes a single-host production deployment into `docker/production`, plus a `.dockerignore` in your project root. Nothing in the package reads these files: they are deployment inputs you own and tune, and they are the one step that is not preselected, since most projects add them later than the code.

Four containers, three of them running the same image and differing only in the entrypoint compose gives them:

| Service | Role |
|---|---|
| `app` | php-fpm. Its entrypoint waits for the database and cache, runs `migrate --force --isolated`, warms the caches, publishes `public/` into the shared volume, then `exec php-fpm` |
| `nginx` | Stock image. Serves static files and proxies PHP to `app:9000` |
| `queue` | One `queue:work` process, restarted by docker when it exits on `--max-time` or `--max-jobs` |
| `scheduler` | `schedule:work`, so sub-minute tasks fire. Never scale it past one instance |

Requests reach them through nginx on the host, which terminates TLS and proxies to `127.0.0.1:3000`:

```
Internet -> host nginx (TLS) -> 127.0.0.1:3000 -> nginx container -> app:9000
```

| Path | Purpose |
|---|---|
| `docker/production/Dockerfile` | Multi-stage build. A `quality` stage runs Pint; the `app` stage is last, so a build without `--target` produces the shippable image |
| `docker/production/docker-compose.yml` | The four services, healthchecks, memory and CPU caps, log rotation, external network and storage volume |
| `docker/production/entrypoint/` | One entrypoint per role, each announcing every step so a failed deploy names the step that died |
| `docker/production/php/` | `php.ini`, `opcache.ini`, and a pool config mounted as `zzz-custom.conf` so it beats the stock `pm.max_children = 5` |
| `docker/production/nginx/default.conf` | Container nginx: fastcgi to `app:9000`, dotfile and project-file denies |
| `docker/production/vps-nginx/` | Host nginx site config and the maintenance page it serves during a deploy |
| `docker/production/README.md` | Every variable the compose file reads, the sizing tables, the timeout ladder, and the post-deploy checks |

What it deliberately does not include: a CI pipeline. Whatever builds the image also writes the environment file, and that is specific to your Jenkins, Actions, or shell script. `docker/production/README.md` lists every key it has to produce.

Files ship with `myapp` and `example.com` placeholders and defaults sized for a 4 GB host. Three of them are load-bearing:

- `pm.max_children` and `APP_MEM_LIMIT` move together, `max_children × 48MB + 128MB ≤ APP_MEM_LIMIT`. Raising the pool alone turns a slow request into an OOM kill.
- The container port is published on `127.0.0.1`. That binding, not your firewall, is what keeps the stack off the internet: docker's DNAT rules are traversed before UFW's filter rules, so a container published on `0.0.0.0` is reachable even with `ufw deny` on the port.
- The healthchecks probe `GET /api/health` and assert `"database":"ok"` in the body, because the kit's cleanup step removes the framework's `health: '/up'` route. The installer warns if that route is not registered.

A deploy is `docker compose up -d`, never `down`, so only the services whose image changed are recreated and the queue worker gets its `stop_grace_period` to finish the job in flight. The app container is replaced rather than reloaded, so requests fail for as long as its entrypoint takes, typically 15 to 45 seconds dominated by migrations. `artisan down` cannot cover that window, since maintenance mode is rendered by the PHP that is missing; the host nginx maps upstream 502, 503 and 504 onto a static page instead.

An existing file is never replaced without `--force`, so a config already tuned to its host survives a second install run.

## Non-Interactive Use

Every step can be named explicitly, which is what CI and scripted setup should do:

```bash
php artisan essentials:install \
    --features=database --features=models --features=dates --features=api \
    --packages=sanctum --packages=permission \
    --redis=predis --redis-cache \
    --timezone --tooling --docker \
    --no-interaction
```

| Option | Effect |
|---|---|
| `--features=*` | Features to generate |
| `--packages=*` | Packages to install |
| `--redis=` | Configure a Redis client: `none`, `phpredis`, or `predis` |
| `--redis-cache` | Use Redis as the cache store |
| `--timezone` | Set the application timezone to `Africa/Algiers` |
| `--tooling` | Install and configure the quality tooling |
| `--docker` | Write the production Docker deployment into `docker/production` |
| `--cleanup` | Remove files a JSON API does not need |
| `--all` | Generate every feature |
| `--force` | Overwrite existing files, and allow cleanup on a dirty tree |

## Upgrading

There is no upgrade path, by design. The kit generates code you own, and it does not come back to change it. Upgrading the package only changes what a future `essentials:install` would write.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Essentials Kit! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [GTS-MEGHNI](https://github.com/gts-meghni)
- [All Contributors](../../contributors)

## License

Laravel Essentials Kit is open-sourced software licensed under the [MIT license](LICENSE.md).
