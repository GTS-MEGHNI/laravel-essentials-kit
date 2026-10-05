---
name: laravel-essentials-kit-development
description: >
  Install and work with Laravel Essentials Kit generated code in Laravel applications.
license: MIT
metadata:
  author: GTS-MEGHNI
---

# Laravel Essentials Kit

Use this skill when a Laravel application installs `gts-meghni/laravel-essentials-kit`, or when working with the code that kit has already generated.

## Primary Goal

- run `essentials:install` with the narrowest selection that satisfies the request, and edit the generated code directly afterwards

## Key Fact

The kit is a generator, not a runtime dependency. It writes code into the application and then does nothing. There is no package configuration file, no service provider applying behavior from inside the vendor directory, and no upgrade path. Everything the kit installs lives in `app/` and is owned by the application.

Never reach for package configuration to change behavior. Edit the generated file.

## Workflow

### 1. Determine what already exists

Check for generated files before installing anything:

- `app/Providers/EssentialsServiceProvider.php`: framework defaults
- `app/Support/ApiResponse.php`: response envelope
- `app/Exceptions/ApiExceptionRenderer.php`: global exception rendering
- `app/Http/Middleware/ForceJsonResponse.php`, `app/Http/Middleware/RequestId.php`
- `app/Support/PhoneNumber.php`, `app/Rules/AlgerianPhoneNumber.php`
- `app/Support/Otp/`: one time password generator and store, configured by `config/otp.php`
- `app/Models/Province.php`, `app/Models/Commune.php`: Algerian administrative division, seeded from `database/data/algeria.json`
- `routes/api/client.php`, `routes/api/backoffice.php`: route groups mounted from `routes/api.php`
- `app/OpenApi/`: OpenAPI attribute classes, present when `darkaonline/l5-swagger` was installed
- `docker/production/`: the production deployment, present when the docker step has run

If the relevant file exists, edit it. Do not re-run the installer to change behavior; re-running requires `--force` and overwrites local edits.

### 2. Install only what is missing

```bash
php artisan essentials:install --features=api --no-interaction
```

Features: `database`, `models`, `dates`, `security`, `observability`, `api`, `route-groups`, `health`, `otp`, `phone`, `geo`.

Pass `--no-interaction` in any scripted context. Without explicit flags a non-interactive run does nothing at all, which is intended. Other steps are flags, not features: `--packages=*`, `--redis=`, `--redis-cache`, `--timezone`, `--tooling`, `--docker`, `--cleanup`.

`--redis=` takes `none`, `phpredis`, or `predis`. It sets `REDIS_CLIENT`, requires `predis/predis` for the predis client, and adds `REDIS_HOST`, `REDIS_PORT`, and `REDIS_PASSWORD` only when they are absent. `--redis-cache` also sets `CACHE_STORE=redis`. Nothing sets `QUEUE_CONNECTION` or `SESSION_DRIVER`; change those yourself when a worker is actually supervised. `phpredis` is a PECL extension, so the installer never tries to install it.

Do not run `php artisan install:api` first. The installer creates `routes/api.php` and adds `api:` to `withRouting()` itself, without pulling in Sanctum.

### 3. Use the generated API surface

Return responses through the envelope:

```php
return ApiResponse::success(data: UserResource::collection($users));
return ApiResponse::paginated($users);
return ApiResponse::error(message: 'Not found.', status: 404);
return ApiResponse::noContent();
```

Do not add per-controller try/catch blocks. `ApiExceptionRenderer` already converts every uncaught throwable into the same envelope, including validation, authentication, authorization, and HTTP exceptions.

To vary error messages by route group, edit the `messages()` method in `ApiExceptionRenderer`.

The API middleware and exception rendering are wired in `bootstrap/app.php`, not in a service provider. That patch also adds `$middleware->trustProxies(at: '*')`, which is what makes `https://` URLs, signed URLs, and real client IPs work behind a reverse proxy. Do not replace it with `URL::forceScheme('https')`: that corrects the URLs while `$request->ip()` still returns the proxy, so the rate limiter keys every client the same and the logs show one address. Narrow the `'*'` only if the application becomes reachable without going through the proxy.

### 4. Document endpoints

When `app/OpenApi/` exists, every endpoint is documented by its own attribute-only class under `app/OpenApi/Client/Endpoints/` or `app/OpenApi/Backoffice/Endpoints/`. Copy the generated `HealthEndpoint` as the template.

The two definitions are separate documents: `config/l5-swagger.php` scans `app/OpenApi/Client` for one and `app/OpenApi/Backoffice` for the other, with `app/OpenApi/Schemas` and `app/OpenApi/Parameters` shared by both. Put a schema used by one side only in that side's directory.

Reference the shared schemas rather than restating the envelope:

```php
content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
```

Access is guarded by `app/Http/Middleware/ProtectApiDocs`: `L5_SWAGGER_ENABLED=false` returns 404, and `L5_SWAGGER_USERNAME` plus `L5_SWAGGER_PASSWORD` demand HTTP basic credentials. Set both in production.

### 5. Use the geographic data

When `app/Models/Province.php` exists, the Algerian administrative division is already modelled. Do not add another provinces or communes table, and do not re-derive the data from an external source.

```php
Province::where('code', '16')->first();          // codes are zero padded strings, "01" not 1
$province->communes;
$commune->translatedName();                       // current locale, falling back to French
```

Names live in a `name` JSON column cast to an array, keyed `ar`, `fr`, `en`. Query a locale with `where('name->fr', ...)`. Sorting a full commune list by localized name has no index behind it, so paginate or filter by province first.

Re-seed with `php artisan db:seed --class=AlgeriaGeoSeeder`, which updates in place rather than duplicating.

### 6. Keep Telescope out of production

The installer registers Telescope from `AppServiceProvider::register()`, behind `$this->app->environment('local')` and a `class_exists()` check, instead of listing `App\Providers\TelescopeServiceProvider` in `bootstrap/providers.php`. Telescope is a dev dependency, so naming it in the manifest fatals every request after `composer install --no-dev`. Leave that conditional in place, and never move the provider back into the manifest.

### 7. Read the timezone from the environment

When `config/app.php` reads `env('APP_TIMEZONE', 'Africa/Algiers')`, the timezone step has run. Change the zone through `APP_TIMEZONE` in the environment, not by editing the config default, so deployments can differ.

### 8. Use one health endpoint

`GET /api/health` is the health check. The framework's `health: '/up'` is removed by the cleanup step once that route exists, so do not reference `/up` and do not add it back. `/api/health` does not answer during `php artisan down`; if a probe must survive maintenance mode, say so rather than reinstating `/up` silently.

### 9. Issue one time passwords through the store

When `app/Support/Otp/` exists, resolve `OtpStore` and use it. Do not generate codes inline, and do not add a second cache key holding a code.

```php
$code = app(OtpStore::class)->issue($phone);          // then send it yourself
app(OtpStore::class)->verify($phone, $code);          // true once, then consumed
```

`OtpGenerator` only produces digits. Attempt limits, expiry, and comparison live in `OtpStore`, so put new lifecycle behavior there rather than in a generator subclass.

Delivery is not part of the feature. Send the code from application code, whether a notification, a job, or an SMS client, and keep that out of `app/Support/Otp/`.

Codes are `111111` under `local` and `testing`, and random everywhere else, including staging. There is no configuration flag for this and none should be added: a flag copied into production would make every code guessable. Tests can rely on `111111` without arranging anything.

### 10. Treat the production deployment as owned config

`docker/production/` is written by `--docker` and read by nothing in the package. Edit the files; never re-run the installer to change them, and note that a second run keeps every existing file unless `--force` is passed.

`docker/production/README.md` is the reference for environment keys, sizing tables, the timeout ladder, and post-deploy checks. Read it before changing a value there.

Four constraints hold across those files:

- `pm.max_children` in `php/php-fpm.conf` and `APP_MEM_LIMIT` in `docker-compose.yml` move together: `max_children x 48MB + 128MB <= APP_MEM_LIMIT`. Raising the pool alone converts a slow request into an OOM kill.
- The published port stays on `127.0.0.1`. Docker's DNAT rules run before UFW's filter rules, so `HOST_IP=0.0.0.0` exposes the stack to the internet and no firewall rule takes it back.
- Healthchecks probe `GET /api/health` and assert `"database":"ok"` in the body, never `/up`.
- Deploy with `docker compose up -d`, never `down`, or the queue worker loses the job in flight and every service is recreated.

`env()` outside `config/` returns `null` in this deployment, because the entrypoint runs `config:cache` and the framework then never reads the environment file again. It cannot reproduce locally. Use `config('services.foo.key')` in application code, and add the `config/` entry in the same commit as a new environment key.

No CI pipeline ships with the step. Whatever builds the image must also write the environment file the compose file and containers read.

## Rules, References, and Templates

Read before executing:

- `app/Providers/EssentialsServiceProvider.php` in the target application, to see which defaults are active

## Examples

- The app needs consistent JSON errors: run `essentials:install --features=api`, then delete any pre-existing exception handling that duplicates the renderer.
- The app should stop allowing `migrate:fresh` in production: run `essentials:install --features=database`, which generates `bootDatabase()`.
- Validation messages need to differ per module: edit `messages()` in the generated `ApiExceptionRenderer`, not any package configuration.

## Anti-patterns

- do not look for `config/laravel-essentials-kit.php`; it does not exist
- do not re-run the installer with `--force` to tweak behavior, since it discards local edits
- do not wrap controller bodies in try/catch when the API feature is installed
- do not use the cleanup step on a repository with uncommitted changes
- do not call `env()` outside `config/` in an application deployed with the docker step
- do not point a healthcheck, probe, or uptime monitor at `/up`; that route is removed
